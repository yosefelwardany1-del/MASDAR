<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Hr\LeaveQueries;
use App\Services\Hr\LeaveService;
use Illuminate\Http\Request;

/**
 * Leave (spec §5.7): the requests, with decisions for whoever approves leave, and every
 * employee's balances for a year. Requests made here are made by HR for an employee; each
 * employee requests their own on "My leave".
 */
class LeaveController extends Controller
{
    public function __construct(private LeaveService $service, private LeaveQueries $queries) {}

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['requests', 'balances'], true) ? $request->query('tab') : 'requests';
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected', 'cancelled', 'all'], true) ? $request->query('status') : 'all';
        $year = (int) $request->query('year', now()->year);
        $types = $this->queries->types();

        $requests = $this->queries->requests()
            ->when($status !== 'all', fn ($q) => $this->queries->whereStatus($q, [$status]))
            ->with(['employee', 'latestDecision.decider', 'requester'])
            ->orderByRaw('CASE WHEN '.LeaveQueries::STATUS_SQL." = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('starts_on')
            ->paginate(30)->withQueryString();

        $employees = Employee::query()->onStaff()->orderBy('name')->get();

        return view('hr.leave.index', [
            'tab' => $tab,
            'status' => $status,
            'year' => $year,
            'types' => $types,
            'requests' => $requests,
            'employees' => $employees,
            'balances' => $tab === 'balances' ? $employees->mapWithKeys(fn (Employee $e) => [$e->id => collect($this->queries->balances($e, $year))->keyBy('type')]) : collect(),
            'pendingCount' => $this->queries->pendingCount(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request) + $request->validate(['employee_id' => ['required', 'integer', 'exists:employees,id']]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $this->service->request($employee, $data, $request->user());

        return redirect()->route('leave.index', ['tab' => 'requests'])->with('success', __('Leave request recorded for :name.', ['name' => $employee->name]));
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        $this->service->approve($leaveRequest, $request->user(), $request->input('note'));

        return back()->with('success', __('Leave approved.'));
    }

    public function reject(Request $request, LeaveRequest $leaveRequest)
    {
        $this->service->reject($leaveRequest, $request->user(), $request->input('note'));

        return back()->with('success', __('Leave not approved.'));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        $this->service->cancel($leaveRequest, $request->user(), $request->input('note'));

        return back()->with('success', __('Leave request cancelled.'));
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'leave_type' => ['required', 'string', 'max:40'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'days' => ['nullable', 'string', 'max:10'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate(self::rules());
    }
}
