<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Set one account's password from the server shell, without the password ever being
 * on a command line, in a chat, or in a shell history.
 *
 * For the break-glass account and first-time setup, so a password never has to be pasted
 * somewhere it will be remembered.
 *
 *   php artisan user:password admin@example.com
 *
 * The password is prompted for, hidden, and confirmed. --generate prints a random one
 * once instead, for a first-time setup where nobody has chosen one yet.
 */
class SetUserPassword extends Command
{
    protected $signature = 'user:password
                            {email : The account\'s e-mail address}
                            {--generate : Make a random 24-character password and print it once}';

    protected $description = 'Set an account\'s password interactively (never on the command line)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $this->error("No account with the address {$email}.");

            return self::FAILURE;
        }

        if ($this->option('generate')) {
            $password = Str::password(24);
            $this->newLine();
            $this->line('  New password (shown once, not logged):');
            $this->line("  <fg=yellow>{$password}</>");
            $this->newLine();
        } else {
            $password = (string) $this->secret('New password (hidden)');
            $again = (string) $this->secret('Once more');

            if ($password !== $again) {
                $this->error('The two entries do not match. Nothing changed.');

                return self::FAILURE;
            }
            if (strlen($password) < 12) {
                // Longer than the web form's floor of 8: this is the account that keeps a
                // password after everyone else's is retired.
                $this->error('Use at least 12 characters. Nothing changed.');

                return self::FAILURE;
            }
        }

        // The model cast hashes it; the remember token is rotated so every other
        // browser holding a "remember me" cookie for this account is signed out.
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        Log::info('Password set from the console', ['user_id' => $user->id]);
        $this->info("Password updated for {$user->email}.");

        return self::SUCCESS;
    }
}
