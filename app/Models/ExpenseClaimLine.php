<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One receipt on an expense claim: its date, kind (expense_category), amount, and the PI or container it was for. */
class ExpenseClaimLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount' => 'decimal:4'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }
}
