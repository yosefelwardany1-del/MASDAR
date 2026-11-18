<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attendance mark for an employee on a day (spec §5.7). Append-only (trigger): the latest
 * record of a day counts; nothing recorded on a working day means present. Written only by
 * App\Services\Hr\AttendanceService.
 */
class AttendanceRecord extends Model
{
    public const UPDATED_AT = null;

    public const STATUSES = ['present', 'absent', 'half_day', 'late', 'remote'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'created_at' => 'datetime', 'overtime_hours' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'present' => __('Present'),
            'absent' => __('Absent'),
            'half_day' => __('Half day'),
            'late' => __('Late arrival'),
            'remote' => __('Remote'),
            default => $status,
        };
    }

    /** The unpaid days a mark stands for: an absence is one, a half day half. */
    public static function unpaidDays(string $status): string
    {
        return match ($status) {
            'absent' => '1',
            'half_day' => '0.5',
            default => '0',
        };
    }
}
