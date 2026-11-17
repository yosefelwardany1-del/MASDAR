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

    /**
     * The note in the reader's language. Notes the system writes when another module completes
     * or reopens a step (shipping, goods receipt, payments) are stored in English, the way
     * OrderStepService's callers word them, and translated here; a person's note shows as typed.
     */
    public function displayNote(): ?string
    {
        $note = $this->note;
        if ($note === null || $note === '') {
            return $note;
        }
        if ($note === 'All cartons received') {
            return __('All cartons received');
        }
        if (! preg_match('/^(Booked on|Unbooked from|Loaded in|Unloaded from|Payment) (\S+)$/', $note, $m)) {
            return $note;
        }

        return match ($m[1]) {
            'Booked on' => __('Booked on :ref', ['ref' => $m[2]]),
            'Unbooked from' => __('Unbooked from :ref', ['ref' => $m[2]]),
            'Loaded in' => __('Loaded in :ref', ['ref' => $m[2]]),
            'Unloaded from' => __('Unloaded from :ref', ['ref' => $m[2]]),
            'Payment' => __('Payment :ref', ['ref' => $m[2]]),
        };
    }
}
