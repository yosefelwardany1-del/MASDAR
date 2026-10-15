<?php

use App\Services\Notifications\Channels\InAppNotificationChannel;

return [

    /*
    | Delivery channels. NotificationService fans every notification out to each ENABLED
    | channel; a channel failure never breaks the action that raised it. In-app is the
    | baseline. WhatsApp and e-mail arrive with the notification engine (Sub-project 3,
    | spec §4.4) — as channels here, never as a second notification path.
    */
    'channels' => [
        'in_app' => [
            'enabled' => true,
            'class' => InAppNotificationChannel::class,
        ],
    ],

];
