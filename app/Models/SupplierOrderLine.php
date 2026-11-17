<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A line of a supplier order: one PI line, at the supplier's price. total_qty and amount are
 * generated columns. Written only by App\Services\SupplierOrders\SupplierOrderService.
 */
class SupplierOrderLine extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'total_qty', 'amount'];

    protected function casts(): array
    {
        return [
            'cartons' => 'decimal:4', 'qty_per_carton' => 'decimal:4', 'total_qty' => 'decimal:4',
            'unit_price' => 'decimal:4', 'amount' => 'decimal:4', 'released_at' => 'datetime',
        ];
    }

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function piLine(): BelongsTo
    {
        return $this->belongsTo(PiLine::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function loads(): HasMany
    {
        return $this->hasMany(ContainerLoad::class);
    }
}
