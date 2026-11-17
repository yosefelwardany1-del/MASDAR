<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A journal entry: built as a draft, then posted once — posting checks it balances (I1) and is
 * outside a closed period (I3); a posted entry never changes (I2). Written only by
 * App\Services\Accounting\PostingService.
 */
class JournalEntry extends Model
{
    use HasAttachments;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function attachmentModule(): string
    {
        return 'accounting';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** A draft carries a provisional number until it is posted. */
    public function displayNumber(): string
    {
        return $this->isPosted() ? $this->number : __('Draft').' #'.$this->id;
    }

    /** The document or payment this entry was posted from, as [label, url or null]; null for a manual entry. */
    public function sourceLink(): ?array
    {
        if ($this->source_type === null || $this->source_id === null) {
            return null;
        }

        [$route, $label] = match ($this->source_type) {
            'payment' => ['payments.show', fn () => Payment::query()->whereKey($this->source_id)->value('number')],
            'proforma_invoice' => ['proforma-invoices.show', fn () => ($pi = ProformaInvoice::query()->find($this->source_id)) ? $pi->reference() : null],
            'supplier_order' => ['supplier-orders.show', fn () => SupplierOrder::query()->whereKey($this->source_id)->value('number')],
            'container' => ['containers.show', fn () => Container::query()->whereKey($this->source_id)->value('container_no')],
            default => [null, fn () => null],
        };

        return [
            (string) ($label() ?? $this->source_type.' #'.$this->source_id),
            $route && \Illuminate\Support\Facades\Route::has($route) ? route($route, $this->source_id) : null,
        ];
    }
}
