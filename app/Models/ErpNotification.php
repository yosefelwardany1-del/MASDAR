<?php

namespace App\Models;

use App\Support\Text;
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
        'icon', 'url', 'is_read', 'read_at', 'texts',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'texts' => 'array',
    ];

    /** B31: the title in the reader's language; a row from before then is shown as written. */
    public function displayTitle(?string $locale = null): string
    {
        return isset($this->texts['title']) ? Text::fromArray($this->texts['title'])->render($locale) : (string) $this->title;
    }

    public function displayMessage(?string $locale = null): string
    {
        return isset($this->texts['message']) ? Text::fromArray($this->texts['message'])->render($locale) : (string) $this->message;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
