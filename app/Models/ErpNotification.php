<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single in-app notification row. Notifications are RAISED through
 * App\Services\Notifications\NotificationService (the one authority) — do not
 * create rows directly.
 */
class ErpNotification extends Model
{
    protected $table = 'erp_notifications';

    protected $fillable = [
        'user_id', 'title', 'message', 'type', 'category',
        'icon', 'url', 'is_read', 'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
