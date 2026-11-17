<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
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
}
