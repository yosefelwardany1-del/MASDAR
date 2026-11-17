<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A container of a shipment: its status is its latest event; its contents are its loads.
 * Written only by App\Services\Shipping\ShipmentService.
 */
class Container extends Model
{
    use HasAttachments, HasFactory;

    protected $guarded = ['id'];

    public function attachmentModule(): string
    {
        return 'containers';
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContainerEvent::class)->orderBy('id');
    }

    public function loads(): HasMany
    {
        return $this->hasMany(ContainerLoad::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
