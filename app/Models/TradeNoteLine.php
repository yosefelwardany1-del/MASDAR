<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line of a credit or debit note; amount = quantity × unit price (generated column). */
class TradeNoteLine extends Model
{
    protected $guarded = ['id', 'amount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'amount' => 'decimal:4'];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(TradeNote::class, 'trade_note_id');
    }
}
