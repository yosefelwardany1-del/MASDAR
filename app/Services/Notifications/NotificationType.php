<?php

namespace App\Services\Notifications;

/**
 * Catalogue of notification categories, stored on erp_notifications.category. One place
 * to add a category keeps keys consistent across modules. Sub-project 3 replaces this with
 * the event → template → channel engine (spec §4.4).
 */
final class NotificationType
{
    // Accounts: somebody signed in with Google from an allowed domain and waits for a
    // role (raised to the users who can edit users), and the answer when they are let in.
    public const USER_PENDING = 'user.pending';

    public const USER_APPROVED = 'user.approved';

    public const SYSTEM = 'system';
}
