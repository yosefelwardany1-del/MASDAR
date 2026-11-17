<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /** The container's current status: its latest event (I8), correction or not. */
    public function latestEvent(): HasOne
    {
        return $this->hasOne(ContainerEvent::class)->latestOfMany('id');
    }

    /** The usable CBM of the container's type (Lookup container_type meta.cbm), or null. */
    public function capacityCbm(): ?string
    {
        return $this->typeMeta('cbm');
    }

    /** The maximum KG of the container's type (Lookup container_type meta.max_kg), or null. */
    public function capacityKg(): ?string
    {
        return $this->typeMeta('max_kg');
    }

    private function typeMeta(string $key): ?string
    {
        if (! $this->container_type) {
            return null;
        }
        $meta = Lookup::query()->where('list', 'container_type')->where('code', $this->container_type)->value('meta');
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;
        $value = $meta[$key] ?? null;

        return is_numeric($value) && (float) $value > 0 ? (string) $value : null;
    }
}
