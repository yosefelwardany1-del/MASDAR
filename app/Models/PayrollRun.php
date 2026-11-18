<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A month's payroll in one currency (spec §5.7): draft → approved → (void). An approved run and
 * its lines never change (triggers); a void run frees the month to be run again. Written only by
 * App\Services\Hr\PayrollService.
 */
class PayrollRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period' => 'date', 'pay_date' => 'date', 'approved_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** "November 2026" in the reader's language. */
    public function periodLabel(): string
    {
        return $this->period->locale(app()->getLocale())->isoFormat('MMMM YYYY');
    }

    /** The last day of the month: the date the payroll is booked on. */
    public function periodEnd(): string
    {
        return $this->period->copy()->endOfMonth()->toDateString();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => __('Draft'),
            'approved' => __('Approved'),
            'void' => __('Void'),
            default => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'approved' => 'done',
            'void' => 'neutral',
            default => 'info',
        };
    }
}
