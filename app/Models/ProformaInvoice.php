<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One revision of a Proforma Invoice (spec §5.2). Issued means immutable (I4, trigger); a change
 * is a new revision of the same pi_number. Written only by
 * App\Services\ProformaInvoices\ProformaInvoiceService.
 */
class ProformaInvoice extends Model
{
    use HasAttachments, HasFactory;

    public const STATUSES = ['draft', 'issued', 'accepted', 'confirmed', 'cancelled', 'superseded'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean', 'po_date' => 'date', 'deposit_percent' => 'decimal:2',
            'issued_at' => 'datetime', 'accepted_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function attachmentModule(): string
    {
        return 'proforma_invoices';
    }

    /** A PI's files follow the screen and the own-clients rule (spec §6). */
    public function attachmentAllows(User $user, string $level): bool
    {
        $screen = 'proforma_invoices.'.($level === 'delete' ? 'edit' : $level);

        return $user->can($screen)
            && Visibility::scopeClients(Party::query()->whereKey($this->client_id), $user)->exists();
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PiLine::class)->orderBy('line_no');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** "PI-2026-0001" or "PI-2026-0001 rev 2". */
    public function reference(): string
    {
        return $this->revision > 1 ? "{$this->pi_number} rev {$this->revision}" : $this->pi_number;
    }

    /** The status in the user's language. */
    public function statusLabel(): string
    {
        return self::statusName((string) $this->status);
    }

    /** A status's name in the user's language. */
    public static function statusName(string $status): string
    {
        return match ($status) {
            'draft' => __('Draft'),
            'issued' => __('Issued'),
            'accepted' => __('Accepted'),
            'confirmed' => __('Confirmed'),
            'cancelled' => __('Cancelled'),
            'superseded' => __('Superseded'),
            default => $status,
        };
    }

    /**
     * Adds `total_amount` (SUM of the line amounts) and `paid_amount` (the plan's "PI paid
     * amounts" contract: client receipts in this revision's currency over every revision of
     * the number) to each row, both computed in SQL as numeric(18,4) strings.
     */
    public function scopeWithAmounts(Builder $query): Builder
    {
        return $query->addSelect([
            'total_amount' => PiLine::query()
                ->selectRaw('COALESCE(SUM(pi_lines.amount), 0)::numeric(18,4)')
                ->whereColumn('pi_lines.proforma_invoice_id', 'proforma_invoices.id'),
            'paid_amount' => Payment::query()
                ->selectRaw('COALESCE(SUM(payments.signed_amount), 0)::numeric(18,4)')
                ->join('proforma_invoices as paid_rev', 'paid_rev.id', '=', 'payments.proforma_invoice_id')
                ->whereColumn('paid_rev.pi_number', 'proforma_invoices.pi_number')
                ->whereColumn('payments.currency', 'proforma_invoices.currency')
                ->where('payments.kind', 'client_receipt'),
        ]);
    }

    /** Client receipts in this revision's currency over every revision of the number, "0.0000" if none. */
    public function paidAmount(): string
    {
        return (string) Payment::query()
            ->join('proforma_invoices as paid_rev', 'paid_rev.id', '=', 'payments.proforma_invoice_id')
            ->where('paid_rev.pi_number', $this->pi_number)
            ->where('payments.currency', $this->currency)
            ->where('payments.kind', 'client_receipt')
            ->selectRaw('COALESCE(SUM(payments.signed_amount), 0)::numeric(18,4) AS paid')
            ->value('paid');
    }

    /**
     * The totals row of the PI, summed in SQL: total_qty, amount, total_cbm and total_kg as
     * numeric(18,4) strings.
     *
     * @return object{total_qty: string, amount: string, total_cbm: string, total_kg: string}
     */
    public function lineTotals(): object
    {
        return PiLine::query()->toBase()
            ->where('proforma_invoice_id', $this->id)
            ->selectRaw('COALESCE(SUM(total_qty), 0)::numeric(18,4) AS total_qty, COALESCE(SUM(amount), 0)::numeric(18,4) AS amount,
                COALESCE(SUM(total_cbm), 0)::numeric(18,4) AS total_cbm, COALESCE(SUM(total_kg), 0)::numeric(18,4) AS total_kg')
            ->first();
    }

    /** Every revision of this PI number, oldest first, with their amounts. */
    public function revisionHistory(): Collection
    {
        return static::query()->where('pi_number', $this->pi_number)->withAmounts()->orderBy('revision')->get();
    }
}
