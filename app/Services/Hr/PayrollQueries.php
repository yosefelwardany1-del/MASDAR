<?php

namespace App\Services\Hr;

use App\Models\Payment;
use App\Models\PayrollRun;
use App\Services\Finance\PaymentQueries;
use Illuminate\Support\Facades\DB;

/** Payroll as read (spec §5.7): a run's totals and what is paid against it. Read-only. */
class PayrollQueries
{
    public const AMOUNTS = ['base_salary', 'allowances', 'overtime_pay', 'bonus', 'unpaid_deduction', 'gross', 'social_insurance_employee',
        'housing_fund_employee', 'income_tax', 'other_deductions', 'net', 'social_insurance_employer', 'housing_fund_employer'];

    public function __construct(private PaymentQueries $payments) {}

    /** @return array<string, string> each amount column summed over the run's lines, and the line count */
    public function totals(PayrollRun $run): array
    {
        $select = implode(', ', array_map(fn ($c) => "COALESCE(SUM({$c}), 0) AS {$c}", self::AMOUNTS));
        $row = (array) DB::table('payroll_lines')->where('payroll_run_id', $run->id)->selectRaw($select.', COUNT(*) AS lines')->first();

        return array_map(fn ($v) => (string) $v, $row);
    }

    /** Salaries paid against the run that count (net of reversals), in the run's currency. */
    public function paid(PayrollRun $run): string
    {
        $signed = $this->payments->whereEffective(Payment::query()->where('payroll_run_id', $run->id))->sum('signed_amount');

        return bcmul((string) ($signed ?: '0'), '-1', 4);
    }

    /** Net salaries still to pay. */
    public function unpaid(PayrollRun $run): string
    {
        return bcsub($this->totals($run)['net'], $this->paid($run), 4);
    }
}
