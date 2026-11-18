<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Services\Hr\AttendanceQueries;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\HrCalendar;
use App\Services\Hr\LeaveQueries;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

/**
 * The attendance sheet (spec §5.7): a month of every employee on the staff, with approved
 * leave, holidays and the marks HR records; and the year's public holidays.
 */
class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $service) {}

    public function index(Request $request, AttendanceQueries $attendance, LeaveQueries $leave)
    {
        try {
            $month = CarbonImmutable::createFromFormat('!Y-m', (string) $request->query('month', now()->format('Y-m')))->startOfMonth();
        } catch (\Throwable) {
            $month = CarbonImmutable::now()->startOfMonth();
        }
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $calendar = new HrCalendar;
        $employees = Employee::query()->onStaffBetween($from, $to)->orderBy('name')->get();
        $ids = $employees->pluck('id')->all();

        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $days[$date] = ['date' => CarbonImmutable::parse($date), 'working' => $calendar->isWorkingDay($date), 'holiday' => $calendar->holiday($date)];
        }

        return view('hr.attendance.index', [
            'month' => $month,
            'days' => $days,
            'employees' => $employees,
            'marks' => $attendance->marks($ids, $from, $to),
            'leave' => $leave->approvedByDate($ids, $from, $to),
            'leaveTypes' => $leave->types(includeInactive: true),
            'holidays' => PublicHoliday::query()->whereYear('holiday_on', $month->year)->orderBy('holiday_on')->get(),
            'hasHolidays' => HrCalendar::hasHolidaysFor($month->year),
            'workingDays' => count(array_filter($days, fn ($d) => $d['working'])),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'string', 'max:12'],
            'overtime_hours' => ['nullable', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $this->service->record($employee, $data, $request->user());

        return redirect()->route('attendance.index', ['month' => substr($data['work_date'], 0, 7)])
            ->with('success', __('Attendance saved for :name on :date.', ['name' => $employee->name, 'date' => $data['work_date']]));
    }

    public function storeHoliday(Request $request)
    {
        $data = $request->validate([
            'holiday_on' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:160'],
            'is_workday' => ['nullable', 'boolean'],
        ]);
        $this->service->saveHoliday($data, $request->user());

        return redirect()->to(route('attendance.index', ['month' => substr($data['holiday_on'], 0, 7)]).'#holidays')->with('success', __('Holiday saved.'));
    }

    public function destroyHoliday(Request $request, PublicHoliday $holiday)
    {
        $month = $holiday->holiday_on->format('Y-m');
        $this->service->deleteHoliday($holiday, $request->user());

        return redirect()->to(route('attendance.index', ['month' => $month]).'#holidays')->with('success', __('Holiday removed.'));
    }
}
