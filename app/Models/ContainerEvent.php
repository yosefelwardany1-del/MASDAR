<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A status of a container (I8: forward only along the container_status list, unless it corrects
 * an earlier event). Append-only. Written only by App\Services\Shipping\ContainerTrackingService.
 */
class ContainerEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The earlier event this one corrects (going back along the path), if any. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_event_id');
    }

    public function isCorrection(): bool
    {
        return $this->corrects_event_id !== null;
    }
}
