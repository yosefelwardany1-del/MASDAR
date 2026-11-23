<?php

namespace App\Models;

use App\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A request for leave (spec §5.7). Append-only (trigger): its status is its latest decision
 * (approved, rejected, cancelled), or pending without one. Written only by
 * App\Services\Hr\LeaveService.
 */
class LeaveRequest extends Model
{
    public const UPDATED_AT = null;

    public const PENDING = 'pending';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'created_at' => 'datetime', 'days' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(LeaveDecision::class)->orderBy('id');
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(LeaveDecision::class)->latestOfMany('id');
    }

    public function status(): string
    {
        return $this->latestDecision?->decision ?? self::PENDING;
    }

    public static function statusLabel(string $status): string
    {
        return self::statusText($status)->render();
    }

    /** The status as a Text, for words stored now and read later (B31). */
    public static function statusText(string $status): Text
    {
        return match ($status) {
            'approved' => Text::of('Approved'),
            'rejected' => Text::of('Rejected'),
            'cancelled' => Text::of('Cancelled'),
            default => Text::of('Waiting for a decision'),
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'approved' => 'done',
            'rejected' => 'late',
            'cancelled' => 'neutral',
            default => 'due',
        };
    }
}
