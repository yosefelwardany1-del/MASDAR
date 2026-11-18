<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A quality claim (spec §5.8): short, defective, wrong or damaged goods, raised against a
 * factory (its supplier order) and/or with a client (their PI). Its status is its latest event
 * (claim_events, append-only); the facts change only while it is open. A claim is settled by
 * notes: a credit note to the client, a debit note on the factory. Written only by
 * App\Services\Claims\ClaimService.
 */
class Claim extends Model
{
    use HasAttachments;

    protected $guarded = ['id'];

    public const STATUSES = ['open', 'with_factory', 'agreed', 'rejected', 'closed'];

    /** Statuses after which the claim is finished (its facts no longer change). */
    public const FINISHED = ['rejected', 'closed'];

    protected function casts(): array
    {
        return ['opened_on' => 'date', 'cartons' => 'decimal:4', 'claimed_amount' => 'decimal:4'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_id');
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClaimEvent::class)->orderBy('id');
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(ClaimEvent::class)->latestOfMany('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(TradeNote::class)->orderBy('id');
    }

    public function status(): string
    {
        return $this->latestEvent?->status ?? 'open';
    }

    public function isFinished(): bool
    {
        return in_array($this->status(), self::FINISHED, true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => __('Open'),
            'with_factory' => __('With the factory'),
            'agreed' => __('Agreed'),
            'rejected' => __('Rejected'),
            'closed' => __('Closed'),
            default => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'open' => 'due',
            'with_factory' => 'info',
            'agreed' => 'progress',
            'rejected' => 'skip',
            'closed' => 'done',
            default => 'neutral',
        };
    }

    public function attachmentModule(): string
    {
        return 'claims';
    }

    /** A claim against a factory names it: its files follow who may see suppliers. */
    public function attachmentAllows(User $user, string $level): bool
    {
        $screen = 'claims.'.($level === 'delete' ? 'edit' : $level);
        if (! $user->can($screen)) {
            return false;
        }
        if ($this->supplier_id !== null && ! Visibility::canSeeSupplierPrices($user)) {
            return false;
        }
        if ($this->client_id !== null && Visibility::ownClientsOnly($user)) {
            return (int) $this->client?->account_manager_id === (int) $user->id;
        }

        return true;
    }
}
