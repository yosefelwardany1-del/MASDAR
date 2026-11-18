<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A public holiday, or a make-up working day on a weekend (China's 调休), spec §5.7. The year's
 * dates are entered by MASDAR, never guessed. Written only by App\Services\Hr\AttendanceService.
 */
class PublicHoliday extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['holiday_on' => 'date', 'is_workday' => 'boolean'];
    }
}
