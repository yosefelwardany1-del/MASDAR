<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One factory's part of a client order — one row of the follow-up sheet (spec §5.3, S2).
 * Written only by App\Services\SupplierOrders\SupplierOrderService.
 */
class SupplierOrder extends Model
{
    use HasAttachments, HasFactory;

    public const STATUSES = ['open', 'completed', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confirmation_date' => 'date', 'delivery_date' => 'date', 'deposit_percent' => 'decimal:2'];
    }

    public function attachmentModule(): string
    {
        return 'supplier_orders';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function merchandiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchandiser_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierOrderLine::class);
    }

    public function stepEvents(): HasMany
    {
        return $this->hasMany(SupplierOrderStepEvent::class)->orderBy('id');
    }

    public function dateChanges(): HasMany
    {
        return $this->hasMany(SupplierOrderDateChange::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
