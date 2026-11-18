<?php

namespace App\Services\Hr;

use App\Models\AttendanceRecord;
use App\Models\Employee;

/** Attendance as read (spec §5.7): the latest record of each day counts. Read-only. */
class AttendanceQueries
{
    /**
     * The mark that counts for each employee and day in $from–$to.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, AttendanceRecord>> employee id => date => record
     */
    public function marks(array $employeeIds, string $from, string $to): array
    {
        $map = [];
        $records = AttendanceRecord::query()->whereIn('employee_id', $employeeIds)->whereBetween('work_date', [$from, $to])
            ->orderBy('id')->get();
        foreach ($records as $record) {
            // Later rows replace earlier ones: the latest record of a day counts.
            $map[$record->employee_id][$record->work_date->toDateString()] = $record;
        }

        return $map;
    }

    /** Unpaid days from absences and half days on working days in $from–$to. */
    public function absenceDays(Employee $employee, string $from, string $to, HrCalendar $calendar): string
    {
        $days = '0';
        foreach ($this->marks([$employee->id], $from, $to)[$employee->id] ?? [] as $date => $record) {
            if ($calendar->isWorkingDay($date)) {
                $days = bcadd($days, AttendanceRecord::unpaidDays($record->status), 2);
            }
        }

        return bcadd($days, '0', 2);
    }

    /** Overtime hours recorded in $from–$to. */
    public function overtimeHours(Employee $employee, string $from, string $to): string
    {
        $hours = '0';
        foreach ($this->marks([$employee->id], $from, $to)[$employee->id] ?? [] as $record) {
            $hours = bcadd($hours, (string) $record->overtime_hours, 2);
        }

        return bcadd($hours, '0', 2);
    }
}
