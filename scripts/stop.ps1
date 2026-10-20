$ErrorActionPreference = 'Stop'
$projectPath = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$runtimePath = Join-Path $projectPath 'storage/app/private/runtime.json'
if (-not (Test-Path -LiteralPath $runtimePath)) { Write-Output 'No managed runtime found.'; exit 0 }
$runtime = Get-Content -LiteralPath $runtimePath -Raw | ConvertFrom-Json
if ($runtime.project -ne $projectPath) { throw 'Runtime workspace mismatch.' }
foreach ($record in $runtime.processes) {
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $($record.id)" -ErrorAction SilentlyContinue
    if ($process -and $process.CommandLine.Contains($projectPath)) {
        $actualStart = (Get-Process -Id $record.id).StartTime.ToUniversalTime()
        $expectedStart = [datetime]::Parse($record.started).ToUniversalTime()
        if ([math]::Abs(($actualStart - $expectedStart).TotalSeconds) -lt 2) {
            # Kill only this verified process tree, including artisan's local PHP server.
            & taskkill.exe /PID $record.id /T /F | Out-Null
        }
    }
}
Remove-Item -LiteralPath $runtimePath
Write-Output 'Managed platform processes stopped.'
