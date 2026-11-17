<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp message MASDAR tried to send a client (sent or not, and why). Append-only
 * (trigger). Written only by App\Services\Shipping\ClientContainerUpdates.
 */
class WhatsAppMessage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'whatsapp_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent' => 'boolean', 'created_at' => 'datetime'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }
}
