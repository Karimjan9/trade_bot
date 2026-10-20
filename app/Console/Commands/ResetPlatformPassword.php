<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ResetPlatformPassword extends Command
{
    protected $signature = 'platform:password {email=admin@local.test}';

    protected $description = 'Set an administrator password using a hidden prompt';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Account not found.');

            return self::FAILURE;
        }
        $password = $this->secret('New password (at least 12 characters)');
        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Password must contain at least 12 characters.');

            return self::FAILURE;
        }
        $user->update(['password' => Hash::make($password)]);
        Storage::disk('local')->delete('initial-admin.txt');
        $this->info('Password updated; initial credential file removed.');

        return self::SUCCESS;
    }
}
