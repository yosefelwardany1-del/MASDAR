<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price offer to a client before the PI (spec §5.2, Q-P1): draft → sent → accepted or
 * declined; an accepted quotation becomes one draft PI. A sent quotation and its lines never
 * change (triggers); a revision is a copy. Written only by
 * App\Services\Quotations\QuotationService.
 */
class Quotation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quoted_on' => 'date', 'valid_until' => 'date', 'sent_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('line_no');
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Sent, unanswered, and past its validity. */
    public function isExpired(): bool
    {
        return $this->status === 'sent' && $this->valid_until->toDateString() < now()->toDateString();
    }

    /** The status as shown: an unanswered quotation past its date reads as expired. */
    public function displayStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => __('Draft'),
            'sent' => __('Sent'),
            'accepted' => __('Accepted'),
            'declined' => __('Declined'),
            'expired' => __('Expired'),
            default => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'sent' => 'info',
            'accepted' => 'done',
            'declined' => 'skip',
            'expired' => 'due',
            default => 'neutral',
        };
    }
}
