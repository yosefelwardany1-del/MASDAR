<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passport, residence permit, work permit, visa, contract … of an employee (spec §5.7).
 * Append-only (trigger): a renewal is a new row of the same kind. Written only by
 * App\Services\Hr\EmployeeService.
 */
class EmployeeDocument extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isExpired(?string $today = null): bool
    {
        return $this->expires_on !== null && $this->expires_on->toDateString() < ($today ?? now()->toDateString());
    }

    /** Days until it expires (negative once expired); null when it does not expire. */
    public function daysLeft(): ?int
    {
        return $this->expires_on === null ? null : (int) now()->startOfDay()->diffInDays($this->expires_on->copy()->startOfDay(), false);
    }
}
