<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\LeaveAllowance;
use App\Models\LeaveRequest;
use App\Models\Lookup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Leave as read (spec §5.7): a request's status is its latest decision; a balance is the
 * allowance minus approved days, computed, never stored. Read-only.
 */
class LeaveQueries
{
    /** A request's status in SQL: its latest decision, or pending. */
    public const STATUS_SQL = "COALESCE((SELECT d.decision FROM leave_decisions d WHERE d.leave_request_id = leave_requests.id ORDER BY d.id DESC LIMIT 1), 'pending')";

    /** Requests with their status as a column. */
    public function requests(): Builder
    {
        return LeaveRequest::query()->select('leave_requests.*')->selectRaw(self::STATUS_SQL.' AS status');
    }

    /** @param list<string> $statuses */
    public function whereStatus(Builder $query, array $statuses): Builder
    {
        return $query->whereRaw(self::STATUS_SQL.' IN ('.implode(',', array_fill(0, count($statuses), '?')).')', $statuses);
    }

    /** Active leave types, code => [label, days a year (or null: not counted), paid]. @return array<string, array{label: string, days: ?string, paid: bool}> */
    public function types(bool $includeInactive = false): array
    {
        return Lookup::query()->ofList('leave_type')->when(! $includeInactive, fn ($q) => $q->where('active', true))->get()
            ->mapWithKeys(fn (Lookup $l) => [$l->code => [
                'label' => $l->displayName(),
                'days' => isset($l->meta['days_per_year']) && $l->meta['days_per_year'] !== '' ? bcadd((string) $l->meta['days_per_year'], '0', 2) : null,
                'paid' => (int) ($l->meta['paid'] ?? 1) === 1,
            ]])->all();
    }

    /** The days of $type $employee may take in $year: their own allowance, else the type's; null when the type is not counted. */
    public function allowance(Employee $employee, string $type, int $year): ?string
    {
        $own = LeaveAllowance::query()->where('employee_id', $employee->id)->where('year', $year)->where('leave_type', $type)
            ->orderByDesc('id')->value('days');
        if ($own !== null) {
            return bcadd((string) $own, '0', 2);
        }

        return $this->types(includeInactive: true)[$type]['days'] ?? null;
    }

    /** Days of requests of $type starting in $year with one of $statuses. */
    public function days(int $employeeId, string $type, int $year, array $statuses = ['approved'], ?int $exceptRequestId = null): string
    {
        $query = $this->whereStatus(LeaveRequest::query(), $statuses)
            ->where('employee_id', $employeeId)->where('leave_type', $type)->whereYear('starts_on', $year)
            ->when($exceptRequestId, fn ($q, $id) => $q->whereKeyNot($id));

        return bcadd((string) ($query->sum('days') ?: '0'), '0', 2);
    }

    /**
     * Each active type's allowance, approved and pending days and what is left in $year.
     *
     * @return list<array{type: string, label: string, paid: bool, allowance: ?string, used: string, pending: string, left: ?string}>
     */
    public function balances(Employee $employee, int $year): array
    {
        $rows = [];
        foreach ($this->types() as $code => $type) {
            $allowance = $this->allowance($employee, $code, $year);
            $used = $this->days($employee->id, $code, $year);
            $pending = $this->days($employee->id, $code, $year, ['pending']);
            $rows[] = [
                'type' => $code, 'label' => $type['label'], 'paid' => $type['paid'], 'allowance' => $allowance, 'used' => $used,
                'pending' => $pending, 'left' => $allowance === null ? null : bcsub(bcsub($allowance, $used, 2), $pending, 2),
            ];
        }

        return $rows;
    }

    /** A pending or approved request of $employee touching $from–$to. */
    public function overlaps(Employee $employee, string $from, string $to): bool
    {
        return $this->whereStatus(LeaveRequest::query(), ['pending', 'approved'])
            ->where('employee_id', $employee->id)->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->exists();
    }

    /**
     * Unpaid leave in $from–$to: approved requests of unpaid types, each counted for the share of
     * its working days that fall in the period (a request across two months is split).
     */
    public function unpaidDays(Employee $employee, string $from, string $to, HrCalendar $calendar): string
    {
        $unpaid = array_keys(array_filter($this->types(includeInactive: true), fn (array $t) => ! $t['paid']));
        if ($unpaid === []) {
            return '0.00';
        }
        $total = '0';
        $requests = $this->whereStatus(LeaveRequest::query(), ['approved'])->where('employee_id', $employee->id)
            ->whereIn('leave_type', $unpaid)->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->get();
        foreach ($requests as $request) {
            $all = $calendar->countWorkingDays($request->starts_on->toDateString(), $request->ends_on->toDateString());
            $inside = $calendar->countWorkingDays(max($from, $request->starts_on->toDateString()), min($to, $request->ends_on->toDateString()));
            $share = $all === 0 ? '0' : bcdiv(bcmul((string) $request->days, (string) $inside, 8), (string) $all, 8);
            $total = bcadd($total, $share, 8);
        }

        return Money::round($total);
    }

    /**
     * Approved leave per employee and date in $from–$to, for the attendance sheet.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, string>> employee id => date => leave type
     */
    public function approvedByDate(array $employeeIds, string $from, string $to): array
    {
        $map = [];
        $requests = $this->whereStatus(LeaveRequest::query(), ['approved'])->whereIn('employee_id', $employeeIds)
            ->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->get();
        foreach ($requests as $request) {
            $day = CarbonImmutable::parse(max($from, $request->starts_on->toDateString()));
            $last = min($to, $request->ends_on->toDateString());
            while ($day->toDateString() <= $last) {
                $map[$request->employee_id][$day->toDateString()] = $request->leave_type;
                $day = $day->addDay();
            }
        }

        return $map;
    }

    /** Requests waiting for a decision, oldest first. */
    public function pending(int $limit = 50): Collection
    {
        return $this->whereStatus($this->requests(), ['pending'])->with('employee')->orderBy('created_at')->limit($limit)->get();
    }

    public function pendingCount(): int
    {
        return $this->whereStatus(LeaveRequest::query(), ['pending'])->count();
    }
}
