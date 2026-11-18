<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A factory's quote on a sourcing request (spec §5.8). */
class SupplierQuote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4', 'moq' => 'decimal:4', 'qty_per_carton' => 'decimal:4',
            'cbm_per_carton' => 'decimal:4', 'kg_per_carton' => 'decimal:4',
            'received_on' => 'date', 'valid_until' => 'date', 'lead_time_days' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SourcingRequest::class, 'sourcing_request_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->toDateString() < now()->toDateString();
    }
}
