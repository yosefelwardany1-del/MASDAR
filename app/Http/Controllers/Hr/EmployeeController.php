<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Employee;
use App\Models\Lookup;
use App\Models\User;
use App\Services\Dashboard\AttentionQueries;
use App\Services\Hr\AttendanceQueries;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\HrCalendar;
use App\Services\Hr\LeaveQueries;
use App\Services\Hr\LeaveService;
use Illuminate\Http\Request;

/**
 * The employee file (spec §5.7): the staff list, an employee's page (details, documents,
 * salary, leave, attendance, files) and the forms. Salaries are shown only to those who see
 * payroll; ID numbers and bank details to those who edit the file or see payroll.
 */
class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $employees) {}

    public function index(Request $request, AttentionQueries $attention, LeaveQueries $leave)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'department' => (string) $request->query('department', ''),
            'status' => in_array($request->query('status'), ['current', 'left', 'all'], true) ? $request->query('status') : 'current',
            'documents' => $request->query('documents') === 'expiring' ? 'expiring' : '',
        ];
        $expiring = $request->user()->can('employees.edit') ? $attention->expiringDocuments(1000) : collect();
        $today = now()->toDateString();

        $employees = Employee::query()
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', '%'.$filters['q'].'%')
                ->orWhere('name_local', 'ilike', '%'.$filters['q'].'%')->orWhere('code', 'ilike', '%'.$filters['q'].'%')
                ->orWhere('job_title', 'ilike', '%'.$filters['q'].'%')))
            ->when($filters['department'] !== '', fn ($q) => $q->where('department', $filters['department']))
            ->when($filters['status'] === 'current', fn ($q) => $q->where(fn ($w) => $w->whereNull('left_on')->orWhere('left_on', '>=', $today)))
            ->when($filters['status'] === 'left', fn ($q) => $q->where('left_on', '<', $today))
            ->when($filters['documents'] === 'expiring', fn ($q) => $q->whereIn('id', $expiring->pluck('employee_id')))
            ->with('user')
            ->orderBy('name')
            ->get();

        $onLeaveToday = $leave->whereStatus($leave->requests(), ['approved'])
            ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->pluck('employee_id')->unique();

        return view('hr.employees.index', [
            'employees' => $employees,
            'filters' => $filters,
            'departments' => Lookup::options('department'),
            'locations' => Lookup::options('work_location'),
            'expiring' => $expiring->groupBy('employee_id'),
            'onLeaveToday' => $onLeaveToday,
            'counts' => [
                'staff' => Employee::query()->onStaff()->count(),
                'onLeave' => $onLeaveToday->count(),
                'expiring' => $expiring->count(),
                'pendingLeave' => $request->user()->can('leave.view') ? $leave->pendingCount() : null,
            ],
        ]);
    }

    public function create()
    {
        return view('hr.employees.create', $this->formData(null));
    }

    public function store(Request $request)
    {
        $employee = $this->employees->create($this->validated($request), $request->user());

        return redirect()->route('employees.show', $employee)->with('success', __('Employee :code added.', ['code' => $employee->code]));
    }

    public function show(Request $request, Employee $employee, LeaveQueries $leave, AttendanceQueries $attendance)
    {
        $user = $request->user();
        $year = (int) $request->query('year', now()->year);
        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $employee->load(['documents' => fn ($q) => $q->orderByDesc('id'), 'user', 'attachments']);

        return view('hr.employees.show', [
            'employee' => $employee,
            'seesPrivate' => $user->can('employees.edit') || $user->can('payroll.view'),
            'seesSalary' => $user->can('payroll.view'),
            'current' => $employee->currentDocuments(),
            'salaries' => $user->can('payroll.view') ? $employee->salaries()->with('creator')->orderByDesc('effective_from')->orderByDesc('id')->get() : collect(),
            'salaryNow' => $user->can('payroll.view') ? $employee->salaryOn($today) : null,
            'year' => $year,
            'balances' => $leave->balances($employee, $year),
            'requests' => $leave->requests()->where('employee_id', $employee->id)->with('latestDecision.decider')->orderByDesc('starts_on')->limit(30)->get(),
            'marks' => collect($attendance->marks([$employee->id], $monthStart, $today)[$employee->id] ?? [])->sortKeysDesc(),
            'leaveTypes' => $leave->types(),
            'documentKinds' => Lookup::options('employee_document'),
            'currencies' => Lookup::options('currency'),
            'warningDays' => BusinessSetting::current()->hr_document_warning_days,
            'workingDaysThisMonth' => (new HrCalendar)->countWorkingDays($monthStart, $today),
        ]);
    }

    public function edit(Employee $employee)
    {
        return view('hr.employees.edit', $this->formData($employee));
    }

    public function update(Request $request, Employee $employee)
    {
        $this->employees->update($employee, $this->validated($request), $request->user());

        return redirect()->route('employees.show', $employee)->with('success', __('Employee :code saved.', ['code' => $employee->code]));
    }

    public function storeSalary(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'base_salary' => ['required', 'string', 'max:30'],
            'allowances' => ['nullable', 'string', 'max:30'],
            'currency' => ['nullable', 'string', 'size:3'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->employees->addSalary($employee, $data, $request->user());

        return redirect()->to(route('employees.show', $employee).'#salary')->with('success', __('Salary saved.'));
    }

    public function storeDocument(Request $request, Employee $employee)
    {
        $maxKb = (int) config('attachments.max_size_kb', 10240);
        $mimes = implode(',', config('attachments.allowed_mimes', ['pdf', 'jpg', 'jpeg', 'png']));
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:40'],
            'number' => ['nullable', 'string', 'max:80'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', "max:{$maxKb}", "mimes:{$mimes}"],
        ]);
        $this->employees->addDocument($employee, $data, $request->user(), $request->file('files', []));

        return redirect()->to(route('employees.show', $employee).'#documents')->with('success', __('Document saved.'));
    }

    public function storeAllowance(Request $request, Employee $employee, LeaveService $leave)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'leave_type' => ['required', 'string', 'max:40'],
            'days' => ['required', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $leave->setAllowance($employee, (int) $data['year'], $data['leave_type'], $data['days'], $request->user(), $data['note'] ?? null);

        return redirect()->to(route('employees.show', ['employee' => $employee, 'year' => $data['year']]).'#leave')->with('success', __('Allowance saved.'));
    }

    private function formData(?Employee $employee): array
    {
        return [
            'employee' => $employee,
            'departments' => Lookup::options('department'),
            'locations' => Lookup::options('work_location'),
            'types' => Lookup::options('employment_type'),
            'countries' => Lookup::options('country'),
            'currencies' => Lookup::options('currency'),
            // Users not linked to another employee: the link lets them request their own leave.
            'users' => User::query()->where('status', 'active')
                ->whereNotIn('id', Employee::query()->whereNotNull('user_id')->when($employee, fn ($q) => $q->whereKeyNot($employee->id))->select('user_id'))
                ->orderBy('name')->get(['id', 'name', 'email']),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'name_local' => ['nullable', 'string', 'max:200'],
            'user_id' => ['nullable', 'integer'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:40'],
            'work_location' => ['nullable', 'string', 'max:40'],
            'employment_type' => ['nullable', 'string', 'max:40'],
            'nationality' => ['nullable', 'string', 'max:10'],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:2000'],
            'emergency_contact' => ['nullable', 'string', 'max:2000'],
            'id_number' => ['nullable', 'string', 'max:80'],
            'bank_details' => ['nullable', 'string', 'max:2000'],
            'salary_currency' => ['nullable', 'string', 'size:3'],
            'joined_on' => ['required', 'date_format:Y-m-d'],
            'left_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
