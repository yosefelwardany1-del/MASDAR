<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sourcing request (spec §5.8): what is wanted, gathering factories' quotes to compare.
 * Open → chosen (one quote) or closed. Written only by App\Services\Sourcing\SourcingService.
 */
class SourcingRequest extends Model
{
    use HasAttachments;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['needed_by' => 'date', 'decided_at' => 'datetime', 'quantity' => 'decimal:4', 'target_price' => 'decimal:4'];
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(SupplierQuote::class)->orderBy('id');
    }

    public function chosenQuote(): BelongsTo
    {
        return $this->belongsTo(SupplierQuote::class, 'chosen_quote_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_id');
    }

    public function quotationLine(): BelongsTo
    {
        return $this->belongsTo(QuotationLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => __('Collecting quotes'),
            'chosen' => __('Factory chosen'),
            'closed' => __('Closed'),
            default => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'open' => 'info',
            'chosen' => 'done',
            default => 'skip',
        };
    }

    public function attachmentModule(): string
    {
        return 'sourcing';
    }

    /** Quotes name factories and their prices. */
    public function attachmentAllows(User $user, string $level): bool
    {
        return $user->can('sourcing.'.($level === 'delete' ? 'edit' : $level)) && Visibility::canSeeSupplierPrices($user);
    }
}
