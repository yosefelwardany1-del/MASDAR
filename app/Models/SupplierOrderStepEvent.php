<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A step of a supplier order marked done, skipped or reopened. The latest event per (order,
 * step) is the step's state. Append-only (trigger). Written only by
 * App\Services\SupplierOrders\OrderStepService.
 */
class SupplierOrderStepEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['happened_on' => 'date', 'created_at' => 'datetime'];
    }

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(OrderStepDefinition::class, 'step_definition_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
