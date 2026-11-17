<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real place money sits: a bank account, e-wallet, cash box or payment agent, in one currency
 * (spec §3.5). Written only by App\Services\Finance\MoneyAccountService.
 */
class MoneyAccount extends Model
{
    use HasFactory;

    public const KINDS = ['bank', 'ewallet', 'cash', 'agent'];

    public const LOCATIONS = ['hong_kong', 'mainland', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ledger_account_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
