<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day's rate: units of the book currency for one unit of `currency`. Written only by App\Services\Finance\FxRateService.
 */
class FxRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate_date' => 'date', 'rate' => 'decimal:6'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
