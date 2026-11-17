<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One movement of money (spec §3.5). Append-only: a correction is a reversal row
 * (reverses_payment_id). book_amount, signed_amount and signed_book_amount are generated
 * columns. Written only by App\Services\Finance\PaymentService.
 */
class Payment extends Model
{
    use HasAttachments;

    public const UPDATED_AT = null;

    public const KINDS_IN = ['client_receipt', 'transfer_in', 'other_in'];

    public const KINDS_OUT = ['supplier_payment', 'shipping_payment', 'expense', 'transfer_out', 'other_out'];

    protected $guarded = ['id', 'book_amount', 'signed_amount', 'signed_book_amount'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date', 'amount' => 'decimal:4', 'rate' => 'decimal:6', 'book_amount' => 'decimal:4',
            'signed_amount' => 'decimal:4', 'signed_book_amount' => 'decimal:4', 'created_at' => 'datetime',
        ];
    }

    public function attachmentModule(): string
    {
        return 'payments';
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_payment_id');
    }

    public function approval(): HasOne
    {
        return $this->hasOne(PaymentApproval::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The name of a kind of payment in the user's language. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'client_receipt' => __('Client receipt'),
            'supplier_payment' => __('Supplier payment'),
            'shipping_payment' => __('Shipping payment'),
            'expense' => __('Expense'),
            'other_in' => __('Other money in'),
            'other_out' => __('Other money out'),
            'transfer_out' => __('Transfer out'),
            'transfer_in' => __('Transfer in'),
            default => $kind,
        };
    }

    public function isReversal(): bool
    {
        return $this->reverses_payment_id !== null;
    }

    public function isTransfer(): bool
    {
        return $this->transfer_group !== null;
    }
}
