<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods received (and inspected) for a supplier order line — step 8 of S2. Append-only; a
 * correction is a row with negative cartons naming the receipt it corrects. Written only by
 * App\Services\SupplierOrders\GoodsReceiptService.
 */
class GoodsReceipt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'cartons' => 'decimal:4', 'measured_cbm' => 'decimal:4', 'measured_kg' => 'decimal:4', 'created_at' => 'datetime'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SupplierOrderLine::class, 'supplier_order_line_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The receipt this row corrects (a correction has negative cartons). */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_receipt_id');
    }

    /** The corrections made to this receipt. */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_receipt_id');
    }

    public function isCorrection(): bool
    {
        return $this->corrects_receipt_id !== null;
    }
}
