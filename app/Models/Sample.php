<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A sample (spec §5.8), followed from the request through the factory to the client's answer;
 * its status is its latest event (append-only). Written only by App\Services\Samples\SampleService.
 */
class Sample extends Model
{
    use HasAttachments;

    protected $guarded = ['id'];

    public const STATUSES = ['requested', 'at_factory', 'received', 'sent', 'approved', 'rejected', 'cancelled'];

    /** B26: approved, rejected and cancelled close the sample until it is reopened (requested again). */
    public const FINAL = ['approved', 'rejected', 'cancelled'];

    /** B26: what may follow each status — forward along the way, or cancelled; a closed sample only reopens. */
    public const NEXT = [
        'requested' => ['at_factory', 'received', 'sent', 'cancelled'],
        'at_factory' => ['received', 'sent', 'cancelled'],
        'received' => ['sent', 'approved', 'rejected', 'cancelled'],
        'sent' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['requested'], 'rejected' => ['requested'], 'cancelled' => ['requested'],
    ];

    /** @return list<string> */
    public function nextStatuses(): array
    {
        return self::NEXT[$this->status()] ?? [];
    }

    protected function casts(): array
    {
        return ['requested_on' => 'date', 'needed_by' => 'date', 'quantity' => 'decimal:4'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SampleEvent::class)->orderBy('id');
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(SampleEvent::class)->latestOfMany('id');
    }

    public function status(): string
    {
        return $this->latestEvent?->status ?? 'requested';
    }

    /** The latest "sent" event: the courier and tracking number the client was given. */
    public function shipment(): ?SampleEvent
    {
        return $this->events->where('status', 'sent')->last();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'requested' => __('Requested'),
            'at_factory' => __('Being made at the factory'),
            'received' => __('Received in Yiwu'),
            'sent' => __('Sent to the client'),
            'approved' => __('Approved by the client'),
            'rejected' => __('Rejected by the client'),
            'cancelled' => __('Cancelled'),
            default => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'approved' => 'done',
            'rejected', 'cancelled' => 'skip',
            'sent' => 'info',
            default => 'due',
        };
    }

    public function attachmentModule(): string
    {
        return 'samples';
    }

    public function attachmentAllows(User $user, string $level): bool
    {
        if (! $user->can('samples.'.($level === 'delete' ? 'edit' : $level))) {
            return false;
        }

        return $this->client_id === null || ! Visibility::ownClientsOnly($user) || (int) $this->client?->account_manager_id === (int) $user->id;
    }
}
