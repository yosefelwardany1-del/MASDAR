<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One month's depreciation of one asset, with the journal entry it was posted in (append-only). */
class DepreciationLine extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'amount' => 'decimal:4', 'created_at' => 'datetime'];
    }
}
