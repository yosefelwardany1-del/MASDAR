<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A credit note to a client (kind credit: they owe less on their PI) or a debit note on a
 * factory (kind debit: MASDAR owes less on the supplier order) — spec §5.8. Draft → issued (its
 * number taken then, gapless) → void. An issued note and its lines never change (triggers); it
 * is voided only once its posting is reversed. Written only by
 * App\Services\Claims\TradeNoteService.
 */
class TradeNote extends Model
{
    use HasAttachments;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'issued_at' => 'datetime', 'voided_at' => 'datetime'];
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

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TradeNoteLine::class)->orderBy('line_no');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scopeCredit(Builder $query): Builder
    {
        return $query->where('kind', 'credit');
    }

    public function scopeDebit(Builder $query): Builder
    {
        return $query->where('kind', 'debit');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCredit(): bool
    {
        return $this->kind === 'credit';
    }

    public function total(): string
    {
        return (string) DB::table('trade_note_lines')->where('trade_note_id', $this->id)->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4) AS t')->value('t');
    }

    public function kindLabel(): string
    {
        return $this->isCredit() ? __('Credit note') : __('Debit note');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => __('Draft'),
            'issued' => __('Issued'),
            'void' => __('Void (note)'),
            default => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'issued' => 'done',
            'void' => 'skip',
            default => 'neutral',
        };
    }

    public function attachmentModule(): string
    {
        return 'claims';
    }

    public function attachmentAllows(User $user, string $level): bool
    {
        return $user->can('claims.'.($level === 'delete' ? 'edit' : $level)) && $this->visibleTo($user);
    }

    /** A debit note names a factory; a credit note a client the user may have to manage. */
    public function visibleTo(User $user): bool
    {
        if (! $this->isCredit()) {
            return Visibility::canSeeSupplierPrices($user);
        }
        if (Visibility::ownClientsOnly($user)) {
            return (int) $this->party?->account_manager_id === (int) $user->id;
        }

        return true;
    }
}
