<?php

namespace App\Services\Hr;

use App\Models\BusinessSetting;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Lookup;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Numbering\NumberingService;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of payroll_runs and payroll_lines (spec §5.7). A run is prepared as a draft
 * from the employee file, attendance and leave; its lines are edited while it is a draft; a
 * manager approves it, which freezes it (triggers) and gives it its number from the payroll
 * series, so approved numbers are gapless. An approved run is voided only once nothing paid
 * against it counts and its journal entry is reversed; the month can then be run again.
 */
class PayrollService
{
    public function __construct(
        private NumberingService $numbers,
        private LeaveQueries $leave,
        private AttendanceQueries $attendance,
        private PayrollQueries $queries,
    ) {}

    /** @param array{period?: string, currency?: string, pay_date?: string, note?: string} $data period as YYYY-MM */
    public function prepare(array $data, User $by): PayrollRun
    {
        $this->authorize($by, 'payroll.edit');
        $period = $this->period($data['period'] ?? null);
        $currency = strtoupper((string) ($data['currency'] ?? '') ?: BusinessSetting::current()->payroll_currency);
        if (! array_key_exists($currency, Lookup::options('currency'))) {
            throw ValidationException::withMessages(['currency' => __('Choose a currency from the list of currencies.')]);
        }
        $existing = PayrollRun::query()->where('period', $period)->where('currency', $currency)->where('status', '<>', 'void')->first();
        if ($existing) {
            throw ValidationException::withMessages(['period' => __('The :currency payroll of this month is :number already.', ['currency' => $currency, 'number' => $existing->number])]);
        }
        $payDate = $this->optionalDate($data['pay_date'] ?? null, 'pay_date');

        return DB::transaction(function () use ($period, $currency, $payDate, $data, $by) {
            $run = PayrollRun::create([
                'number' => 'DRAFT-'.Str::upper(Str::random(8)), 'period' => $period, 'currency' => $currency, 'status' => 'draft',
                'pay_date' => $payDate, 'note' => $this->text($data['note'] ?? null), 'created_by' => $by->id,
            ]);
            if ($this->fillLines($run) === 0) {
                throw ValidationException::withMessages(['period' => __('Nobody on the staff that month has a salary in :currency. Set salaries on the employees\' pages first.', ['currency' => $currency])]);
            }
            AuditTrail::record('created', $run, [], ['period' => $period, 'currency' => $currency]);

            return $run;
        });
    }

    /** Rebuild a draft's lines from the employee file, attendance and leave: amounts typed in are lost. */
    public function recalculate(PayrollRun $run, User $by): PayrollRun
    {
        $this->authorize($by, 'payroll.edit');

        return DB::transaction(function () use ($run) {
            $run = $this->lockedDraft($run);
            PayrollLine::query()->where('payroll_run_id', $run->id)->delete();
            if ($this->fillLines($run) === 0) {
                throw ValidationException::withMessages(['period' => __('Nobody on the staff that month has a salary in :currency. Set salaries on the employees\' pages first.', ['currency' => $run->currency])]);
            }
            AuditTrail::record('recalculated', $run, [], []);

            return $run;
        });
    }

    /** @param array{pay_date?: string, note?: string} $data */
    public function updateDraft(PayrollRun $run, array $data, User $by): PayrollRun
    {
        $this->authorize($by, 'payroll.edit');
        $payDate = $this->optionalDate($data['pay_date'] ?? null, 'pay_date');

        return DB::transaction(function () use ($run, $payDate, $data) {
            $run = $this->lockedDraft($run);
            $old = ['pay_date' => $run->pay_date?->toDateString(), 'note' => $run->note];
            $run->update(['pay_date' => $payDate, 'note' => $this->text($data['note'] ?? null)]);
            AuditTrail::record('updated', $run, $old, ['pay_date' => $payDate, 'note' => $run->note]);

            return $run;
        });
    }

    /** @param array<string, mixed> $data PayrollLine::EDITABLE amounts and note */
    public function updateLine(PayrollLine $line, array $data, User $by): PayrollLine
    {
        $this->authorize($by, 'payroll.edit');
        $values = [];
        foreach (PayrollLine::EDITABLE as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = Money::amount($data[$field], $field, decimals: in_array($field, ['overtime_hours', 'unpaid_days'], true) ? 2 : 4);
            }
        }
        $values['note'] = $this->text($data['note'] ?? null);

        return DB::transaction(function () use ($line, $values) {
            $this->lockedDraft($line->run);
            $line = PayrollLine::query()->lockForUpdate()->findOrFail($line->id);
            $after = array_merge($line->only(PayrollLine::EDITABLE), $values);
            $gross = bcsub(bcadd(bcadd(bcadd((string) $line->base_salary, (string) $line->allowances, 4), (string) $after['overtime_pay'], 4), (string) $after['bonus'], 4), (string) $after['unpaid_deduction'], 4);
            $net = $gross;
            foreach (['social_insurance_employee', 'housing_fund_employee', 'income_tax', 'other_deductions'] as $deduction) {
                $net = bcsub($net, (string) $after[$deduction], 4);
            }
            if (bccomp($gross, '0', 4) < 0 || bccomp($net, '0', 4) < 0) {
                throw ValidationException::withMessages(['line' => __('The deductions are more than :name earns this month.', ['name' => $line->employee->name])]);
            }
            $old = $line->only(array_keys($values));
            $line->update($values);
            AuditTrail::record('updated', $line->run, [], ['employee' => $line->employee->code, 'before' => $old, 'after' => $values]);

            return $line;
        });
    }

    public function approve(PayrollRun $run, User $by): PayrollRun
    {
        $this->authorize($by, 'payroll.approve');

        return DB::transaction(function () use ($run, $by) {
            $run = $this->lockedDraft($run);
            if (! $run->lines()->exists()) {
                throw ValidationException::withMessages(['run' => __('A payroll run without lines is not approved.')]);
            }
            $run->update([
                'number' => $this->numbers->next('payroll', $run->period),
                'status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(),
            ]);
            AuditTrail::record('approved', $run, [], ['number' => $run->number, 'net' => $this->queries->totals($run)['net']]);

            return $run;
        });
    }

    public function void(PayrollRun $run, User $by, ?string $reason): PayrollRun
    {
        $this->authorize($by, 'payroll.approve');
        $reason = $this->text($reason);
        if ($reason === null) {
            throw ValidationException::withMessages(['void_reason' => __('Say why the payroll run is voided.')]);
        }

        return DB::transaction(function () use ($run, $by, $reason) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status !== 'approved') {
                throw ValidationException::withMessages(['run' => __('Only an approved payroll run is voided; a draft is deleted.')]);
            }
            if (bccomp((string) (DB::table('payments')->where('payroll_run_id', $run->id)->sum('signed_amount') ?: '0'), '0', 4) !== 0) {
                throw ValidationException::withMessages(['run' => __('Reverse the salary payments of :number first (on the Payments screen).', ['number' => $run->number])]);
            }
            $entry = JournalEntry::query()->where('source_type', PostingService::SOURCE_PAYROLL)->where('source_id', $run->id)
                ->whereNull('reverses_entry_id')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journal_entries as r')->whereColumn('r.reverses_entry_id', 'journal_entries.id'))
                ->first();
            if ($entry) {
                throw ValidationException::withMessages(['run' => __('Reverse journal entry :entry of :number first (on the Accounting screen).', ['entry' => $entry->number, 'number' => $run->number])]);
            }
            $run->update(['status' => 'void', 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => $reason]);
            AuditTrail::record('voided', $run, [], ['reason' => $reason]);

            return $run;
        });
    }

    public function deleteDraft(PayrollRun $run, User $by): void
    {
        $this->authorize($by, 'payroll.edit');
        DB::transaction(function () use ($run) {
            $run = $this->lockedDraft($run);
            PayrollLine::query()->where('payroll_run_id', $run->id)->delete();
            AuditTrail::record('deleted', $run, ['period' => $run->period->toDateString(), 'currency' => $run->currency], []);
            $run->delete();
        });
    }

    /**
     * One line per employee on the staff in the month whose salary at the month end (or their
     * leaving date) is in the run's currency (spec §5.7). Returns the number of lines.
     */
    private function fillLines(PayrollRun $run): int
    {
        $settings = BusinessSetting::current();
        $calendar = new HrCalendar;
        $start = $run->period->toDateString();
        $end = $run->period->copy()->endOfMonth()->toDateString();
        $monthDays = $calendar->countWorkingDays($start, $end);
        $perMonth = (string) $settings->payroll_days_per_month;
        $count = 0;

        foreach (Employee::query()->onStaffBetween($start, $end)->orderBy('code')->get() as $employee) {
            $first = max($start, $employee->joined_on->toDateString());
            $last = $employee->left_on ? min($end, $employee->left_on->toDateString()) : $end;
            $salary = $employee->salaryOn($last);
            if (! $salary || $salary->currency !== $run->currency) {
                continue;
            }
            $fullBase = (string) $salary->base_salary;
            $base = $fullBase;
            $allowances = (string) $salary->allowances;
            // A joiner or a leaver is paid for the working days they were on the staff.
            $employed = $calendar->countWorkingDays($first, $last);
            if ($monthDays > 0 && $employed < $monthDays) {
                $base = Money::div(bcmul($fullBase, (string) $employed, 8), (string) $monthDays);
                $allowances = Money::div(bcmul($allowances, (string) $employed, 8), (string) $monthDays);
            }

            $hours = $this->attendance->overtimeHours($employee, $first, $last);
            $overtimePay = Money::div(bcmul(bcmul($fullBase, $hours, 8), (string) $settings->payroll_overtime_multiplier, 8), bcmul($perMonth, '8', 8));
            $unpaidDays = bcadd($this->leave->unpaidDays($employee, $first, $last, $calendar), $this->attendance->absenceDays($employee, $first, $last, $calendar), 2);
            $deduction = Money::min(Money::div(bcmul($unpaidDays, $fullBase, 8), $perMonth), bcadd(bcadd($base, $allowances, 4), $overtimePay, 4));
            $gross = bcsub(bcadd(bcadd($base, $allowances, 4), $overtimePay, 4), $deduction, 4);

            // Social insurance and housing fund on the base salary, within the bases set (Q-H3: nothing assumed).
            $contributionBase = $fullBase;
            if ($settings->si_base_min !== null) {
                $contributionBase = Money::max($contributionBase, (string) $settings->si_base_min);
            }
            if ($settings->si_base_max !== null) {
                $contributionBase = Money::min($contributionBase, (string) $settings->si_base_max);
            }
            $share = fn ($percent) => $percent === null ? '0' : Money::div(bcmul($contributionBase, (string) $percent, 8), '100');
            $siEmployee = Money::min($share($settings->si_employee_percent), $gross);
            $hfEmployee = Money::min($share($settings->hf_employee_percent), bcsub($gross, $siEmployee, 4));

            PayrollLine::create([
                'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
                'base_salary' => $base, 'allowances' => $allowances, 'overtime_hours' => $hours, 'overtime_pay' => $overtimePay,
                'unpaid_days' => $unpaidDays, 'unpaid_deduction' => $deduction,
                'social_insurance_employee' => $siEmployee, 'housing_fund_employee' => $hfEmployee,
                'social_insurance_employer' => $share($settings->si_employer_percent), 'housing_fund_employer' => $share($settings->hf_employer_percent),
            ]);
            $count++;
        }

        return $count;
    }

    private function lockedDraft(PayrollRun $run): PayrollRun
    {
        $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
        if (! $run->isDraft()) {
            throw ValidationException::withMessages(['run' => __('Payroll run :number is :status: it no longer changes.', ['number' => $run->number, 'status' => mb_strtolower(PayrollRun::statusLabel($run->status))])]);
        }

        return $run;
    }

    private function period(mixed $value): string
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m', (string) $value)->startOfMonth()->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['period' => __('Choose the month.')]);
        }
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
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

    private function authorize(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }
    }
}
