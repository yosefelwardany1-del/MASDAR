<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One Google address that signs in as one account.
 *
 * Most people have exactly one row, created the first time they sign in with Google.
 * Somebody who holds two Workspace mailboxes has two rows pointing at the same user —
 * added by an admin, never by the person, because "this other address is also me" is a
 * claim the system cannot verify on its own.
 *
 * The e-mail is stored lowercased and is unique across the whole table: one address is
 * one person, always.
 */
class UserSsoIdentity extends Model
{
    protected $fillable = [
        'user_id', 'email', 'google_sub', 'provider', 'last_signed_in_at', 'added_by',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'added_by' => 'integer',
        'last_signed_in_at' => 'datetime',
    ];

    protected $attributes = [
        'provider' => 'google',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** The one normalisation every lookup and every write goes through. */
    public static function normalise(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }

    public static function findByEmail(?string $email): ?self
    {
        $email = self::normalise($email);

        return $email === '' ? null : static::query()->where('email', $email)->first();
    }

    /** The part after the @, lowercased, or '' when the address has no domain. */
    public static function domainOf(?string $email): string
    {
        $email = self::normalise($email);
        $at = strrpos($email, '@');

        return $at === false ? '' : substr($email, $at + 1);
    }
}
