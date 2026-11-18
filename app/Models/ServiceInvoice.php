<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * An invoice for what MASDAR charges a client besides goods (spec §5.8): commission, inspection,
 * sourcing, samples, freight recharged. Draft → issued (numbered then) → void; an issued invoice
 * never changes (triggers). Written only by App\Services\ServiceInvoices\ServiceInvoiceService.
 */
class ServiceInvoice extends Model
{
    use HasAttachments;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'due_on' => 'date', 'issued_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_id');
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ServiceInvoiceLine::class)->orderBy('line_no');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function total(): string
    {
        return (string) DB::table('service_invoice_lines')->where('service_invoice_id', $this->id)->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4) AS t')->value('t');
    }

    public function visibleTo(User $user): bool
    {
        return ! Visibility::ownClientsOnly($user) || (int) $this->client?->account_manager_id === (int) $user->id;
    }

    public function attachmentModule(): string
    {
        return 'service_invoices';
    }

    public function attachmentAllows(User $user, string $level): bool
    {
        return $user->can('service_invoices.'.($level === 'delete' ? 'edit' : $level)) && $this->visibleTo($user);
    }
}
