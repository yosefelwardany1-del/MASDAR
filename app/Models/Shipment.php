<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
