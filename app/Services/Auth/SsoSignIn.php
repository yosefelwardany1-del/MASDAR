<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserSsoIdentity;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationType;
use App\Support\Text;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The rules of signing in with a Google account. No HTTP in here — GoogleSsoClient has
 * already turned the conversation with Google into an SsoProfile.
 *
 * THE RULES, in the order they are applied:
 *
 *   1. Google must have verified the address. An unverified address is refused.
 *   2. The domain must be on the allow-list (config/sso.php). Anything else is refused
 *      BEFORE the system looks the address up, so an outside account learns nothing.
 *   3. The address is looked up: first in user_sso_identities (a linked second address),
 *      then against users.email, both case-insensitively. A match is that account — and
 *      NOTHING about it changes: not its roles, not its password.
 *   4. No match creates a PENDING account: no roles, cannot hold a session, until
 *      somebody who may edit users assigns it. Everybody who may is notified.
 *   5. A matched account still has to be allowed in — the same User::canSignIn() the
 *      password path asks. Pending shows the waiting page; inactive is refused.
 *
 * PASSWORDS. When config('sso.passwords_retired') is on, an address on an allowed domain
 * can no longer sign in with a password, except the break-glass addresses. Addresses on
 * any other domain are untouched.
 */
class SsoSignIn
{
    public function __construct(private NotificationService $notifications) {}

    // -----------------------------------------------------------------------------
    // Domain rules
    // -----------------------------------------------------------------------------

    /** @return list<string> */
    public function allowedDomains(): array
    {
        return array_values(array_filter((array) config('sso.allowed_domains', [])));
    }

    /** Is this address on one of OUR domains? */
    public function isOrgAddress(?string $email): bool
    {
        $domain = UserSsoIdentity::domainOf($email);

        return $domain !== '' && in_array($domain, $this->allowedDomains(), true);
    }

    public function isBreakGlass(?string $email): bool
    {
        $email = UserSsoIdentity::normalise($email);

        return $email !== '' && in_array($email, (array) config('sso.breakglass_emails', []), true);
    }

    /**
     * Must this address sign in with Google rather than a password? Only when the switch
     * is on, only for the allowed domains, never for the break-glass list. Answered from
     * the ADDRESS, before any password is checked.
     */
    public function passwordRetiredFor(?string $email): bool
    {
        if (! config('sso.passwords_retired', false)) {
            return false;
        }

        return $this->isOrgAddress($email) && ! $this->isBreakGlass($email);
    }

    // -----------------------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------------------

    public function resolve(SsoProfile $profile): SsoOutcome
    {
        if (! $profile->emailVerified) {
            Log::warning('Google sign-in refused: address not verified by Google', ['domain' => $profile->domain()]);

            return SsoOutcome::refused(__('Google has not verified that e-mail address, so it cannot be used to sign in.'));
        }

        if (! $this->isOrgAddress($profile->email)) {
            // Logged by domain only. The address itself is somebody else's personal
            // data and the log is not the place for it.
            Log::notice('Google sign-in refused: domain not allowed', ['domain' => $profile->domain()]);

            return SsoOutcome::refused($this->outsideDomainMessage());
        }

        $identity = UserSsoIdentity::findByEmail($profile->email);
        $user = $identity?->user;

        if (! $user) {
            $user = $this->userByPrimaryEmail($profile->email);
            if ($user) {
                $identity = $this->link($user, $profile);
            }
        }

        $created = false;
        if (! $user) {
            [$user, $identity, $created] = $this->createPending($profile);
        }

        if ($user->status === 'pending') {
            return SsoOutcome::pending($user, $created);
        }

        if (! $user->canSignIn()) {
            return SsoOutcome::refused(__('This account is not active. Contact your administrator.'));
        }

        $this->recordSignIn($user, $identity, $profile);

        return SsoOutcome::signedIn($user);
    }

    /**
     * Link a second Google address to an existing account, by an admin's hand.
     *
     * The address must be on one of our domains, must not already be somebody's
     * identity, and must not be somebody ELSE's primary e-mail — that address would
     * have signed in as them, and two accounts claiming it is the kind of ambiguity
     * this table exists to rule out.
     *
     * @throws \InvalidArgumentException with a message safe to show the admin
     */
    public function linkAddress(User $user, string $email, User $actor): UserSsoIdentity
    {
        $email = UserSsoIdentity::normalise($email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(__('That is not a valid e-mail address.'));
        }
        if (! $this->isOrgAddress($email)) {
            throw new \InvalidArgumentException(__('Only addresses on :domains can be linked.', ['domains' => implode(', ', $this->allowedDomains())]));
        }
        if (UserSsoIdentity::findByEmail($email)) {
            throw new \InvalidArgumentException(__('That address is already linked to an account.'));
        }
        $owner = $this->userByPrimaryEmail($email);
        if ($owner && $owner->id !== $user->id) {
            throw new \InvalidArgumentException(__('That address is the sign-in e-mail of another account.'));
        }

        return UserSsoIdentity::create([
            'user_id' => $user->id,
            'email' => $email,
            'added_by' => $actor->id,
        ]);
    }

    /** The people who should hear that somebody new is waiting: active users who may edit users. */
    public function approvers(): array
    {
        return User::permission('users.edit')
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
    }

    public function outsideDomainMessage(): string
    {
        return __('Sign in with your company Google account.');
    }

    // -----------------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------------

    private function userByPrimaryEmail(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [UserSsoIdentity::normalise($email)])->first();
    }

    private function link(User $user, SsoProfile $profile): UserSsoIdentity
    {
        return UserSsoIdentity::firstOrCreate(
            ['email' => $profile->email],
            ['user_id' => $user->id, 'google_sub' => $profile->sub],
        );
    }

    /**
     * @return array{0:User,1:UserSsoIdentity,2:bool}
     */
    private function createPending(SsoProfile $profile): array
    {
        try {
            $result = DB::transaction(function () use ($profile) {
                $user = User::create([
                    'name' => $profile->displayName(),
                    'email' => $profile->email,
                    // A password nobody knows. The account is Google-only from birth;
                    // an admin can set one later if a break-glass ever needs it.
                    'password' => Str::random(64),
                    'status' => 'pending',
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $identity = UserSsoIdentity::create([
                    'user_id' => $user->id,
                    'email' => $profile->email,
                    'google_sub' => $profile->sub,
                    'last_signed_in_at' => now(),
                ]);

                return [$user, $identity];
            });
        } catch (QueryException $e) {
            // Two callbacks for the same new address at once: the unique index made
            // the second one lose. It is the same person — hand back the winner.
            $identity = UserSsoIdentity::findByEmail($profile->email);
            $user = $identity?->user ?? $this->userByPrimaryEmail($profile->email);
            if (! $user) {
                throw $e;
            }

            return [$user, $identity ?? $this->link($user, $profile), false];
        }

        [$user, $identity] = $result;

        Log::info('Google sign-in: pending account created', ['user_id' => $user->id, 'domain' => $profile->domain()]);
        $this->notifyApprovers($user);

        return [$user, $identity, true];
    }

    private function notifyApprovers(User $user): void
    {
        try {
            $this->notifications->send(
                $this->approvers(),
                Text::of('New sign-in request'),
                Text::of(':name (:email) signed in with Google and is waiting for a role.', ['name' => $user->name, 'email' => $user->email]),
                [
                    'type' => 'info',
                    'category' => NotificationType::USER_PENDING,
                    'icon' => 'person-plus',
                    'url' => Route::has('users.edit') ? route('users.edit', $user) : null,
                ],
            );
        } catch (\Throwable $e) {
            report($e); // the account exists either way; a missed notice is recoverable
        }
    }

    private function recordSignIn(User $user, ?UserSsoIdentity $identity, SsoProfile $profile): void
    {
        if ($identity) {
            if ($identity->google_sub && $identity->google_sub !== $profile->sub) {
                // The Google account behind this address was recreated. Google still
                // verified the address, so this is a note for the log, not a refusal.
                Log::warning('Google sign-in: subject changed for a linked address', ['user_id' => $user->id]);
            }
            $identity->forceFill(['google_sub' => $profile->sub, 'last_signed_in_at' => now()])->save();
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }
}
