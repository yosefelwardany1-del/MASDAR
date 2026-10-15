<?php

namespace App\Services\Notifications\Channels;

/**
 * A delivery channel for a single notification to a single recipient. The payload
 * is a normalized array produced by NotificationService:
 *   user_id, title, message, type, category, icon, url.
 *
 * Implementations must be self-contained and side-effect-isolated: NotificationService
 * already guards each send() in a try/catch so a failing channel can never break the
 * business action that triggered the notification.
 */
interface NotificationChannel
{
    public function send(array $payload): void;
}
