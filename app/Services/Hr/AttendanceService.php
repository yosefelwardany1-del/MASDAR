<?php

namespace App\Services\Hr;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of attendance_records (append-only: the latest record of a day counts) and of
 * public_holidays (spec §5.7). Both need leave.edit ("Leave & attendance").
 */
class AttendanceService
{
    /** @param array{work_date?: string, status?: string, overtime_hours?: string, note?: string} $data */
    public function record(Employee $employee, array $data, User $by): AttendanceRecord
    {
        $this->authorize($by);
        $date = $this->date($data['work_date'] ?? null, 'work_date');
        $status = (string) ($data['status'] ?? '');
        if (! in_array($status, AttendanceRecord::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => __('Choose how the day went.')]);
        }
        $overtime = Money::amount($data['overtime_hours'] ?? '0', 'overtime_hours', decimals: 2);
        if (bccomp($overtime, '24', 2) > 0) {
            throw ValidationException::withMessages(['overtime_hours' => __('A day has at most 24 hours.')]);
        }
        if (! $employee->isOnStaff($date)) {
            throw ValidationException::withMessages(['work_date' => __(':name is not on the staff on :date.', ['name' => $employee->name, 'date' => $date])]);
        }
        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages(['work_date' => __('Attendance is recorded for today or earlier.')]);
        }

        return DB::transaction(function () use ($employee, $date, $status, $overtime, $data, $by) {
            $record = AttendanceRecord::create([
                'employee_id' => $employee->id, 'work_date' => $date, 'status' => $status, 'overtime_hours' => $overtime,
                'note' => $this->text($data['note'] ?? null), 'created_by' => $by->id,
            ]);
            AuditTrail::record('attendance_recorded', $employee, [], ['work_date' => $date, 'status' => $status, 'overtime_hours' => $overtime]);

            return $record;
        });
    }

    /** @param array{holiday_on?: string, name?: string, is_workday?: bool} $data */
    public function saveHoliday(array $data, User $by): PublicHoliday
    {
        $this->authorize($by);
        $date = $this->date($data['holiday_on'] ?? null, 'holiday_on');
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('Name the holiday.')]);
        }

        try {
            return DB::transaction(function () use ($date, $name, $data) {
                $holiday = PublicHoliday::create(['holiday_on' => $date, 'name' => mb_substr($name, 0, 160), 'is_workday' => (bool) ($data['is_workday'] ?? false)]);
                AuditTrail::record('created', $holiday, [], $holiday->only(['name', 'is_workday']) + ['holiday_on' => $date]);

                return $holiday;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['holiday_on' => __(':date is already in the list.', ['date' => $date])]);
        }
    }

    public function deleteHoliday(PublicHoliday $holiday, User $by): void
    {
        $this->authorize($by);
        DB::transaction(function () use ($holiday) {
            AuditTrail::record('deleted', $holiday, $holiday->only(['name', 'is_workday']) + ['holiday_on' => $holiday->holiday_on->toDateString()], []);
            $holiday->delete();
        });
    }

    private function authorize(User $user): void
    {
        if (! $user->can('leave.edit')) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }
    }

    private function date(mixed $value, string $field): string
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', (string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('Enter a date.')]);
        }
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 2000);
    }
}
