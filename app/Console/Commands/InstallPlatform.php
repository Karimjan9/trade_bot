<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstallPlatform extends Command
{
    protected $signature = 'platform:install {--email=admin@local.test}';

    protected $description = 'Migrate database and create the first administrator with a random local password';

    public function handle(): int
    {
        $email = $this->option('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Valid email required.');

            return self::FAILURE;
        }
        $this->call('migrate', ['--force' => true]);
        if (User::exists()) {
            $this->info('Administrator already exists; credentials unchanged.');

            return self::SUCCESS;
        }
        $password = Str::password(24, symbols: false);
        User::create(['name' => 'Admin', 'email' => $email, 'password' => Hash::make($password)]);
        Storage::disk('local')->put('initial-admin.txt', "Signal Forge LOCAL administrator\nEmail: {$email}\nPassword: {$password}\n\nKeep this file private. Reset using php artisan platform:password.\n");
        $this->info('Administrator created. Credentials: storage/app/private/initial-admin.txt');

        return self::SUCCESS;
    }
}
