<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This supplier order is booked on this shipment" (spec §5.5, step 9 of §5.3). Added on
 * booking, removed on unbooking, never changed; it cannot be removed while the order's goods
 * are loaded on the shipment (trigger). Written only by App\Services\Shipping\ShipmentService.
 */
class ShipmentOrder extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
