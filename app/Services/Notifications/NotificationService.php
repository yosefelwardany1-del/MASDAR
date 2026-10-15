<?php

namespace App\Services\Notifications;

use App\Models\ErpNotification;
use App\Models\User;
use App\Services\Notifications\Channels\NotificationChannel;
use Illuminate\Support\Collection;

/**
 * The single authority for raising notifications. Every module pushes through here
 * instead of writing ErpNotification rows directly, which gives us, in one place:
 *  - fan-out to every enabled delivery channel (in-app now; email/sms/push later),
 *  - resilient delivery (a channel failure never breaks the business action),
 *  - the unread counter + centre read helpers.
 *
 * Recipients are chosen by the caller — callers are responsible for only notifying
 * users who may see the underlying record (fail-closed). Helpers accept User models,
 * ids, nulls (skipped) or any iterable mix.
 */
class NotificationService
{
    /** @var NotificationChannel[] */
    private array $channels;

    public function __construct()
    {
        $this->channels = collect(config('notifications.channels', []))
            ->filter(fn ($c) => ($c['enabled'] ?? false) && ! empty($c['class']))
            ->map(fn ($c) => app($c['class']))
            ->values()
            ->all();
    }

    /**
     * Send a notification to one or many recipients.
     *
     * @param  User|int|iterable<User|int|null>|null  $recipients
     * @param  array{type?:string,category?:string,icon?:string,url?:string}  $opts
     * @return int number of recipients notified
     */
    public function send($recipients, string $title, string $message, array $opts = []): int
    {
        $sent = 0;
        foreach ($this->normalizeRecipients($recipients) as $userId) {
            $payload = [
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'type' => $opts['type'] ?? 'info',
                'category' => $opts['category'] ?? null,
                'icon' => $opts['icon'] ?? 'bell',
                'url' => $opts['url'] ?? null,
            ];

            foreach ($this->channels as $channel) {
                try {
                    $channel->send($payload);
                } catch (\Throwable $e) {
                    // Best-effort: never let a delivery channel break the primary action.
                    report($e);
                }
            }
            $sent++;
        }

        return $sent;
    }

    /** Unread count for the bell. */
    public function unreadCountFor(User|int|null $user): int
    {
        $userId = $user instanceof User ? $user->id : $user;
        if (! $userId) {
            return 0;
        }

        return $this->scopeForUser($userId)->where('is_read', false)->count();
    }

    /** Most recent notifications for the bell dropdown. */
    public function recentFor(User|int|null $user, int $limit = 8): Collection
    {
        $userId = $user instanceof User ? $user->id : $user;
        if (! $userId) {
            return collect();
        }

        return $this->scopeForUser($userId)->latest()->limit($limit)->get();
    }

    /**
     * Base query for a user's notifications: their own rows only. Reused by the controller
     * and the helpers so ownership is defined in exactly one place.
     */
    public function scopeForUser(int $userId)
    {
        return ErpNotification::query()->where('user_id', $userId);
    }

    /**
     * @param  User|int|iterable<User|int|null>|null  $recipients
     * @return int[] unique, non-empty user ids
     */
    private function normalizeRecipients($recipients): array
    {
        if ($recipients instanceof User) {
            return [$recipients->id];
        }
        if ($recipients === null) {
            return [];
        }
        // A single scalar id. Accept a numeric string as well as an int: the Postgres
        // PDO driver returns integer columns as strings, so $model->user_id (uncast)
        // arrives as e.g. '23' in production while sqlite/MySQL hand back a real int.
        if (is_int($recipients) || (is_string($recipients) && is_numeric($recipients))) {
            return (int) $recipients > 0 ? [(int) $recipients] : [];
        }
        // Anything else that isn't iterable (bool, float, non-numeric string) — nothing
        // to notify. Guarding here keeps foreach() from fataling on a scalar.
        if (! is_iterable($recipients)) {
            return [];
        }

        $ids = [];
        foreach ($recipients as $r) {
            if ($r instanceof User) {
                $ids[] = $r->id;
            } elseif (is_int($r) && $r > 0) {
                $ids[] = $r;
            } elseif (is_numeric($r) && (int) $r > 0) {
                $ids[] = (int) $r;
            }
        }

        return array_values(array_unique($ids));
    }
}
