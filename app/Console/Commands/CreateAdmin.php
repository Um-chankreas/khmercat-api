<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates an admin account, or makes an existing account an admin. The
 * password is asked for (hidden) rather than kept in code or a seeder:
 *
 *   php artisan admin:create admin@example.com
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email} {--name=Khmer Cat Admin} {--password= : Skip the prompt (avoid on shared machines: it lands in shell history)}';

    protected $description = 'Create an admin account, or promote an existing one';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $user = User::withTrashed()->where('email', $email)->first();

        $password = $this->option('password') ?: $this->secret('Password (leave empty to keep an existing account\'s)');
        if (! $user && ! $password) {
            $this->error('A new account needs a password.');

            return self::FAILURE;
        }
        if ($password && mb_strlen($password) < 8) {
            $this->error('Use at least 8 characters.');

            return self::FAILURE;
        }

        if (! $user) {
            $user = new User([
                'name' => $this->option('name'),
                'email' => $email,
                'username' => $this->uniqueUsername(),
            ]);
        }
        if ($password) {
            $user->password = $password; // hashed by the model cast
        }
        $user->email_verified_at ??= now();
        $user->deactivated_at = null;
        $user->is_admin = true;
        $user->save();
        if ($user->trashed()) {
            $user->restore();
        }

        $this->info("{$email} is an admin (user #{$user->id}, @{$user->username}).");

        return self::SUCCESS;
    }

    private function uniqueUsername(): string
    {
        do {
            $username = 'khmercat_admin_'.Str::lower(Str::random(5));
        } while (User::withTrashed()->where('username', $username)->exists());

        return $username;
    }
}
