<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
}
