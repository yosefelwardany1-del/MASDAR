<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\LeaveAllowance;
use App\Models\LeaveDecision;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of leave_requests, leave_decisions and leave_allowances (spec §5.7).
 * Requests and decisions are append-only; PostgreSQL keeps the order of decisions and refuses
 * a decision on one's own request. An employee requests their own leave through the user
 * linked to their file; HR (leave.edit) requests for anyone; leave.approve decides.
 */
class LeaveService
{
    public function __construct(private LeaveQueries $queries, private NotificationService $notifications) {}

    /** @param array{leave_type?: string, starts_on?: string, ends_on?: string, days?: string, reason?: string} $data */
    public function request(Employee $employee, array $data, User $by): LeaveRequest
    {
        $own = $employee->user_id !== null && $employee->user_id === $by->id;
        if (! $own && ! $by->can('leave.edit')) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }
        $types = $this->queries->types();
        $type = (string) ($data['leave_type'] ?? '');
        if (! isset($types[$type])) {
            throw ValidationException::withMessages(['leave_type' => __('Choose the kind of leave.')]);
        }
        $from = $this->date($data['starts_on'] ?? null, 'starts_on');
        $to = $this->date($data['ends_on'] ?? null, 'ends_on');
        if ($to < $from) {
            throw ValidationException::withMessages(['ends_on' => __('The last day cannot be before the first.')]);
        }
        if (substr($from, 0, 4) !== substr($to, 0, 4)) {
            throw ValidationException::withMessages(['ends_on' => __('Leave across the new year is requested as two requests, one for each year.')]);
        }
        if (! $employee->isOnStaff($from) || ! $employee->isOnStaff($to)) {
            throw ValidationException::withMessages(['starts_on' => __(':name is not on the staff on those dates.', ['name' => $employee->name])]);
        }
        $working = (new HrCalendar)->countWorkingDays($from, $to);
        $days = filled($data['days'] ?? null) ? $this->days($data['days'], $from, $to) : bcadd((string) $working, '0', 2);
        if (bccomp($days, '0', 2) <= 0) {
            throw ValidationException::withMessages(['ends_on' => __('There is no working day between those dates.')]);
        }

        return DB::transaction(function () use ($employee, $type, $types, $from, $to, $days, $data, $by) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            if ($this->queries->overlaps($employee, $from, $to)) {
                throw ValidationException::withMessages(['starts_on' => __('These dates overlap another leave request of :name.', ['name' => $employee->name])]);
            }
            $this->checkBalance($employee, $type, (int) substr($from, 0, 4), $days, ['approved', 'pending'], null, $types[$type]['label']);

            $request = LeaveRequest::create([
                'employee_id' => $employee->id, 'leave_type' => $type, 'starts_on' => $from, 'ends_on' => $to, 'days' => $days,
                'reason' => $this->text($data['reason'] ?? null), 'requested_by' => $by->id,
            ]);
            AuditTrail::record('created', $request, [], $request->only(['employee_id', 'leave_type', 'starts_on', 'ends_on', 'days']));

            DB::afterCommit(fn () => $this->tellApprovers($request, $employee));

            return $request;
        });
    }

    public function approve(LeaveRequest $request, User $by, ?string $note = null): LeaveDecision
    {
        return $this->decide($request, 'approved', $by, $note);
    }

    public function reject(LeaveRequest $request, User $by, ?string $note = null): LeaveDecision
    {
        return $this->decide($request, 'rejected', $by, $note);
    }

    /**
     * Cancel a pending or approved request: HR any time; the employee themselves while it is
     * pending, or approved and not yet started.
     */
    public function cancel(LeaveRequest $request, User $by, ?string $note = null): LeaveDecision
    {
        $request = $request->fresh(['employee', 'latestDecision']);
        $own = $request->employee->user_id === $by->id;
        $status = $request->status();
        $ownMayCancel = $own && ($status === LeaveRequest::PENDING || $request->starts_on->toDateString() > now()->toDateString());
        if (! $by->can('leave.edit') && ! $ownMayCancel) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }
        if (! in_array($status, [LeaveRequest::PENDING, 'approved'], true)) {
            throw ValidationException::withMessages(['decision' => __('This request is already :status.', ['status' => mb_strtolower(LeaveRequest::statusLabel($status))])]);
        }

        return DB::transaction(function () use ($request, $by, $note) {
            $decision = LeaveDecision::create([
                'leave_request_id' => $request->id, 'decision' => 'cancelled', 'decided_by' => $by->id, 'note' => $this->text($note),
            ]);
            AuditTrail::record('cancelled', $request, [], ['note' => $decision->note]);

            return $decision;
        });
    }

    /** An employee's own allowance of a leave type for a year (e.g. statutory annual leave by years worked). */
    public function setAllowance(Employee $employee, int $year, string $type, string $days, User $by, ?string $note = null): LeaveAllowance
    {
        if (! $by->can('leave.edit')) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }
        if (! isset($this->queries->types()[$type])) {
            throw ValidationException::withMessages(['leave_type' => __('Choose the kind of leave.')]);
        }
        if ($year < 2000 || $year > 2100) {
            throw ValidationException::withMessages(['year' => __('Enter a year.')]);
        }
        $days = Money::amount($days, 'days', decimals: 2);
        if (bccomp($days, '366', 2) > 0) {
            throw ValidationException::withMessages(['days' => __('A year has at most 366 days.')]);
        }

        return DB::transaction(function () use ($employee, $year, $type, $days, $by, $note) {
            $allowance = LeaveAllowance::create([
                'employee_id' => $employee->id, 'year' => $year, 'leave_type' => $type, 'days' => $days,
                'note' => $this->text($note), 'created_by' => $by->id,
            ]);
            AuditTrail::record('leave_allowance_set', $employee, [], ['year' => $year, 'leave_type' => $type, 'days' => $days]);

            return $allowance;
        });
    }

    private function decide(LeaveRequest $request, string $decision, User $by, ?string $note): LeaveDecision
    {
        if (! $by->can('leave.approve')) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }
        $request = $request->fresh(['employee', 'latestDecision']);
        if ($request->employee->user_id === $by->id) {
            throw ValidationException::withMessages(['decision' => __('Nobody decides their own leave request.')]);
        }
        if ($request->status() !== LeaveRequest::PENDING) {
            throw ValidationException::withMessages(['decision' => __('This request is already :status.', ['status' => mb_strtolower(LeaveRequest::statusLabel($request->status()))])]);
        }

        return DB::transaction(function () use ($request, $decision, $by, $note) {
            Employee::query()->whereKey($request->employee_id)->lockForUpdate()->first();
            if ($decision === 'approved') {
                $types = $this->queries->types(includeInactive: true);
                $this->checkBalance($request->employee, $request->leave_type, $request->starts_on->year, (string) $request->days, ['approved'], $request->id,
                    $types[$request->leave_type]['label'] ?? $request->leave_type);
            }
            $row = LeaveDecision::create([
                'leave_request_id' => $request->id, 'decision' => $decision, 'decided_by' => $by->id, 'note' => $this->text($note),
            ]);
            AuditTrail::record($decision, $request, [], ['note' => $row->note]);

            DB::afterCommit(fn () => $this->tellEmployee($request, $decision));

            return $row;
        });
    }

    /** Refuses $days of a counted leave type beyond what is left in $year. */
    private function checkBalance(Employee $employee, string $type, int $year, string $days, array $counting, ?int $exceptId, string $label): void
    {
        $allowance = $this->queries->allowance($employee, $type, $year);
        if ($allowance === null) {
            return;
        }
        $taken = $this->queries->days($employee->id, $type, $year, $counting, $exceptId);
        $left = bcsub($allowance, $taken, 2);
        if (bccomp($days, $left, 2) > 0) {
            throw ValidationException::withMessages(['days' => __(':name has :left days of :type left in :year; this asks for :days.', [
                'name' => $employee->name, 'left' => $left, 'type' => mb_strtolower($label), 'year' => $year, 'days' => $days,
            ])]);
        }
    }

    private function tellApprovers(LeaveRequest $request, Employee $employee): void
    {
        $approvers = User::permission('leave.approve')->where('status', 'active')
            ->when($employee->user_id, fn ($q, $id) => $q->whereKeyNot($id))->get();
        foreach ($approvers as $approver) {
            $this->inLocale($approver, fn () => $this->notifications->send($approver, __('Leave to decide'),
                __(':name asks for :days days of :type, :from to :to.', [
                    'name' => $employee->name, 'days' => $request->days, 'type' => mb_strtolower($this->typeLabel($request->leave_type)),
                    'from' => $request->starts_on->toDateString(), 'to' => $request->ends_on->toDateString(),
                ]),
                ['icon' => 'calendar-check', 'category' => 'leave', 'url' => route('leave.index', ['tab' => 'requests'])]));
        }
    }

    private function tellEmployee(LeaveRequest $request, string $decision): void
    {
        $user = $request->employee->user;
        if (! $user) {
            return;
        }
        $this->inLocale($user, fn () => $this->notifications->send($user,
            $decision === 'approved' ? __('Leave approved') : __('Leave not approved'),
            __('Your :type from :from to :to: :status.', [
                'type' => mb_strtolower($this->typeLabel($request->leave_type)), 'from' => $request->starts_on->toDateString(),
                'to' => $request->ends_on->toDateString(), 'status' => mb_strtolower(LeaveRequest::statusLabel($decision)),
            ]),
            ['icon' => 'calendar-check', 'category' => 'leave', 'type' => $decision === 'approved' ? 'success' : 'warning', 'url' => route('my-leave.index')]));
    }

    private function typeLabel(string $code): string
    {
        return $this->queries->types(includeInactive: true)[$code]['label'] ?? $code;
    }

    /** Notifications are stored as text: each is written in its reader's language. */
    private function inLocale(User $user, callable $send): void
    {
        $previous = app()->getLocale();
        app()->setLocale($user->locale ?: config('app.locale'));
        try {
            $send();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function days(mixed $value, string $from, string $to): string
    {
        $days = Money::amount($value, 'days', positive: true, decimals: 2);
        if (bccomp(bcmul($days, '2', 2), bcadd(bcmul($days, '2', 0), '0', 2), 2) !== 0) {
            throw ValidationException::withMessages(['days' => __('Days are counted in whole or half days.')]);
        }
        $calendarDays = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        if (bccomp($days, (string) $calendarDays, 2) > 0) {
            throw ValidationException::withMessages(['days' => __('That is more days than the dates hold.')]);
        }

        return $days;
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
