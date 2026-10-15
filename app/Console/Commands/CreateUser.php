<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates an account from the command line — the first manager and the first system
 * admin of a fresh install, before anybody can use the Users screen.
 *
 *   php artisan user:create manager@example.com "First Manager" --role=manager --generate
 *
 * The password is prompted for (hidden, confirmed), or --generate prints a random one once.
 */
class CreateUser extends Command
{
    protected $signature = 'user:create {email} {name} {--role=* : one or more roles} {--generate : print a random password instead of prompting}';

    protected $description = 'Create an active user with the given roles';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid e-mail address.');

            return self::FAILURE;
        }
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error("An account for {$email} already exists. Use user:password to reset it.");

            return self::FAILURE;
        }

        $roles = array_values(array_unique((array) $this->option('role')));
        $unknown = array_diff($roles, Role::whereIn('name', $roles)->pluck('name')->all());
        if ($unknown !== []) {
            $this->error('Unknown role(s): '.implode(', ', $unknown).'. Run the RolesAndPermissionsSeeder first?');

            return self::FAILURE;
        }

        if ($this->option('generate')) {
            $password = Str::password(20, symbols: false);
        } else {
            $password = (string) $this->secret('Password (hidden)');
            if ($password !== (string) $this->secret('Once more')) {
                $this->error('The two entries do not match. Nothing created.');

                return self::FAILURE;
            }
            if (mb_strlen($password) < 12) {
                $this->error('Use at least 12 characters. Nothing created.');

                return self::FAILURE;
            }
        }

        $user = DB::transaction(function () use ($email, $roles, $password) {
            $user = User::create([
                'name' => (string) $this->argument('name'),
                'email' => $email,
                'password' => Hash::make($password),
                'status' => 'active',
            ]);
            $user->syncRoles($roles);

            return $user;
        });

        $this->info("Created {$user->email} with roles: ".($roles ? implode(', ', $roles) : '(none)').'.');
        if ($this->option('generate')) {
            $this->line("Password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
