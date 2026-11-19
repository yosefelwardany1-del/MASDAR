<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Services\Hr\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A fixed asset (spec §5.8), depreciated straight-line each month from its start date over its
 * life, down to its residual value; what is depreciated is the SUM of its depreciation lines,
 * never stored. Written only by App\Services\Accounting\FixedAssetService.
 */
class FixedAsset extends Model
{
    use HasAttachments;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date', 'depreciation_starts_on' => 'date', 'disposed_on' => 'date',
            'cost' => 'decimal:4', 'residual_value' => 'decimal:4', 'useful_life_months' => 'integer',
        ];
    }

    public function depreciation(): HasMany
    {
        return $this->hasMany(DepreciationLine::class)->orderBy('month');
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function depreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function depreciated(): string
    {
        return (string) DB::table('depreciation_lines')->where('fixed_asset_id', $this->id)->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4) AS t')->value('t');
    }

    public function bookValue(): string
    {
        return bcsub((string) $this->cost, $this->depreciated(), 4);
    }

    /** The straight-line charge for a full month, to 4 places. */
    public function monthlyCharge(): string
    {
        // To the cent (B09); the last month takes what is left, so the cents add up to the cost.
        return bcadd(Money::div(bcsub((string) $this->cost, (string) $this->residual_value, 8), (string) $this->useful_life_months), '0', 4);
    }

    public function attachmentModule(): string
    {
        return 'fixed_assets';
    }
}
