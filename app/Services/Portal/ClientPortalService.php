<?php

namespace App\Services\Portal;

use App\Models\BusinessSetting;
use App\Models\Party;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Client portal accounts (spec §5.8, Q-R2): a user linked to one client party, with no role —
 * it sees only the portal, and only that client's documents. Given and taken away from the
 * client's page by whoever may edit clients and users. The portal is off until switched on
 * (client_portal_enabled).
 */
class ClientPortalService
{
    /** @return array{0: User, 1: string} the account and its first password, shown once */
    public function grant(Party $client, string $email, string $name, User $by): array
    {
        if (! $by->can('clients.edit') || ! $by->can('users.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
        if (! $client->is_client) {
            throw ValidationException::withMessages(['email' => __('Only a client has a portal account.')]);
        }
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => __(':email is not an email address.', ['email' => $email])]);
        }
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('There is already an account with :email.', ['email' => $email])]);
        }
        $password = Str::password(14, symbols: false);

        $user = DB::transaction(function () use ($client, $email, $name, $password) {
            $user = User::create([
                'name' => mb_substr(trim($name) ?: $client->name, 0, 160), 'email' => $email,
                'password' => Hash::make($password), 'status' => 'active', 'locale' => 'en', 'party_id' => $client->id,
            ]);
            AuditTrail::record('portal_access_granted', $client, [], ['email' => $email]);

            return $user;
        });

        return [$user, $password];
    }

    public function revoke(User $account, User $by): void
    {
        if (! $by->can('clients.edit') || ! $by->can('users.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
        if (! $account->isPortalUser()) {
            throw ValidationException::withMessages(['account' => __('That is not a portal account.')]);
        }
        DB::transaction(function () use ($account) {
            $account->forceFill(['status' => 'inactive'])->save();
            $account->signOutEverywhere();
            AuditTrail::record('portal_access_revoked', $account->portalParty, [], ['email' => $account->email]);
        });
    }

    public static function enabled(): bool
    {
        return (bool) BusinessSetting::current()->client_portal_enabled;
    }
}
