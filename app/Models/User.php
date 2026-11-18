<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    /**
     * The same default the column has, so an instance built in memory (a factory, a
     * test's actingAs) carries a status before it is re-read — EnsureAccountCanSignIn
     * asks canSignIn() on every request.
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'phone_e164',
        'avatar',
        'status',
        'last_login',
        'approved_at',
        'approved_by',
        'locale',
        'party_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * URL for this user's profile photo, or null when they have not uploaded one (the
     * caller falls back to initials). Streamed through a route, never a public URL.
     */
    /** The client this account belongs to, for a client portal account (spec §5.8). */
    public function portalParty()
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /** A client's portal account: sees only the portal, never the staff screens. */
    public function isPortalUser(): bool
    {
        return $this->party_id !== null;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar ? route('profile.photo', $this) : null;
    }

    /** The Google addresses that sign in as this account (see UserSsoIdentity). */
    public function ssoIdentities()
    {
        return $this->hasMany(UserSsoIdentity::class)->orderBy('id');
    }

    /** Who approved this account, when it arrived through Google sign-in. */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Signed in with Google from an allowed domain; nobody has assigned it yet. */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * May this account hold a session at all? Allow-list: only 'active' gets in, so a
     * status added later is refused by default. Checked after the credential is accepted
     * and on every request.
     */
    public function canSignIn(): bool
    {
        return $this->status === 'active';
    }

    /**
     * End every sign-in of this account except $keepSessionId (the person changing their
     * own password keeps the tab they are in). Called whenever the password changes: a
     * password is replaced because it may have leaked, and a browser that used it would
     * otherwise keep its session and its "remember me" cookie, which never asks again.
     */
    public function signOutEverywhere(?string $keepSessionId = null): void
    {
        $this->forceFill(['remember_token' => Str::random(60)])->save();

        // Only database sessions can be found by user; the other drivers cannot be listed.
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $this->id)
                ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))
                ->delete();
        }
    }
}
