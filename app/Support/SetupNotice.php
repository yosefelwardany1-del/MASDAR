<?php

namespace App\Support;

use App\Models\User;
use App\Security\Roles;

/**
 * B32: when something on the server is not set up (mail, WhatsApp, PDF), people are told it plainly
 * and to ask their administrator; only the system administrator sees which settings are missing.
 */
final class SetupNotice
{
    public static function for(?User $viewer, string $plain, string $technical): string
    {
        return $viewer?->hasRole(Roles::SYSTEM_ADMIN) ? $technical : $plain;
    }
}
