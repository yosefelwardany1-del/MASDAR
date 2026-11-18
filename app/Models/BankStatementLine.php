<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One line of a bank statement: + money in, − money out. Never changes once imported. */
class BankStatementLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['booked_on' => 'date', 'amount' => 'decimal:4', 'running_amount' => 'decimal:4'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function match(): HasOne
    {
        return $this->hasOne(BankMatch::class);
    }
}
