<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A booking with a shipping company (spec §5.5). Written only by
 * App\Services\Shipping\ShipmentService.
 */
class Shipment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['etd' => 'date', 'eta' => 'date'];
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'shipping_company_id');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The bookings of supplier orders on this shipment (shipment_orders rows). */
    public function bookings(): HasMany
    {
        return $this->hasMany(ShipmentOrder::class)->orderBy('id');
    }

    /** The supplier orders booked on this shipment. */
    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(SupplierOrder::class, 'shipment_orders')->orderBy('supplier_orders.number');
    }
}
