<?php

namespace App\Services\Notifications\Channels;

use App\Models\ErpNotification;

/**
 * The required baseline channel: persists the notification as an ErpNotification row
 * so it appears in the in-app notification centre and unread counter.
 */
class InAppNotificationChannel implements NotificationChannel
{
    public function send(array $payload): void
    {
        ErpNotification::create([
            'user_id' => $payload['user_id'],
            'title' => $payload['title'],
            'message' => $payload['message'],
            'texts' => $payload['texts'] ?? null,
            'type' => $payload['type'] ?? 'info',
            'category' => $payload['category'] ?? null,
            'icon' => $payload['icon'] ?? 'bell',
            'url' => $payload['url'] ?? null,
            'is_read' => false,
        ]);
    }
}
