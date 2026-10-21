param([int]$Port = 8020)
$ErrorActionPreference = 'Stop'
$projectPath = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$phpPath = (Get-Command php -ErrorAction Stop).Source
$running = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
if ($running) { throw "Port $Port is already in use. Use -Port with another port or stop the existing local server." }
$logDirectory = Join-Path $projectPath 'storage/logs'
$runtimePath = Join-Path $projectPath 'storage/app/private/runtime.json'
if (Test-Path -LiteralPath $runtimePath) {
    $oldState = Get-Content -LiteralPath $runtimePath -Raw | ConvertFrom-Json
    foreach ($record in $oldState.processes) {
        $existing = Get-CimInstance Win32_Process -Filter "ProcessId = $($record.id)" -ErrorAction SilentlyContinue
        if ($existing -and $existing.CommandLine.Contains($projectPath)) { throw 'A managed platform process is still running. Run scripts/stop.ps1 first.' }
    }
}
$artisanPath = Join-Path $projectPath 'artisan'
$definitions = @(
    @{ name = 'server'; arguments = @($artisanPath, 'serve', '--host=127.0.0.1', "--port=$Port", '--no-reload') },
    @{ name = 'queue'; arguments = @($artisanPath, 'queue:work', '--tries=5', '--timeout=120', '--sleep=1') },
    @{ name = 'ml'; arguments = @($artisanPath, 'queue:work', '--queue=ml', '--tries=1', '--timeout=180', '--sleep=2') },
    @{ name = 'scheduler'; arguments = @($artisanPath, 'schedule:work') }
)
$started = @()
try {
    foreach ($definition in $definitions) {
        $argumentLine = ($definition.arguments | ForEach-Object { '"' + $_ + '"' }) -join ' '
        $process = Start-Process -FilePath $phpPath -ArgumentList $argumentLine -WorkingDirectory $projectPath -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logDirectory ($definition.name + '.out.log')) -RedirectStandardError (Join-Path $logDirectory ($definition.name + '.err.log'))
        $started += @{ name = $definition.name; id = $process.Id; started = $process.StartTime.ToUniversalTime().ToString('o') }
    }
    @{ project = $projectPath; port = $Port; processes = $started } | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $runtimePath -Encoding UTF8
} catch {
    foreach ($record in $started) { Stop-Process -Id $record.id -ErrorAction SilentlyContinue }
    throw
}
Write-Output "Signal Forge: http://127.0.0.1:$Port"
Write-Output 'Server, signal queue, ML queue and scheduler started in the background. Stop: ./scripts/stop.ps1'
