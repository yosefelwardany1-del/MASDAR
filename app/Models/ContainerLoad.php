<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cartons of a supplier order line put into (or, negative, taken out of) a container. I6: never
 * more than received (trigger). Append-only. Written only by
 * App\Services\Shipping\ContainerTrackingService.
 */
class ContainerLoad extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cartons' => 'decimal:4', 'loaded_on' => 'date', 'created_at' => 'datetime'];
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SupplierOrderLine::class, 'supplier_order_line_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
