<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Hr\LeaveQueries;
use App\Services\Hr\LeaveService;
use Illuminate\Http\Request;

/**
 * "My leave" (spec §5.7): any signed-in user linked to an employee file sees their balances and
 * requests, asks for leave and cancels what has not started. Needs sign-in only; everything is
 * the caller's own.
 */
class MyLeaveController extends Controller
{
    public function index(Request $request, LeaveQueries $queries)
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        $year = (int) $request->query('year', now()->year);

        return view('hr.my-leave', [
            'employee' => $employee,
            'year' => $year,
            'types' => $queries->types(),
            'balances' => $employee ? $queries->balances($employee, $year) : [],
            'requests' => $employee ? $queries->requests()->where('employee_id', $employee->id)->with('latestDecision.decider')->orderByDesc('starts_on')->limit(50)->get() : collect(),
        ]);
    }

    public function store(Request $request, LeaveService $service)
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->firstOrFail();
        $service->request($employee, $request->validate(LeaveController::rules()), $request->user());

        return redirect()->route('my-leave.index')->with('success', __('Your request is sent. You will be told of the decision.'));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest, LeaveService $service)
    {
        abort_unless($leaveRequest->employee?->user_id === $request->user()->id, 404);
        $service->cancel($leaveRequest, $request->user(), $request->input('note'));

        return redirect()->route('my-leave.index')->with('success', __('Leave request cancelled.'));
    }
}
