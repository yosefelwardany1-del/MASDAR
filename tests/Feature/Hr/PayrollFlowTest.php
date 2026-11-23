<?php

namespace Tests\Feature\Hr;

use App\Models\BusinessSetting;
use App\Models\Employee;
use App\Models\JournalLine;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Security\Roles;
use App\Services\Accounting\PostingService;
use App\Services\Finance\FxRateService;
use App\Services\Finance\PaymentService;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\LeaveService;
use App\Services\Hr\PayrollQueries;
use App\Services\Hr\PayrollService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.7: a month's payroll from the employee file, attendance and leave, to the books and the bank. */
class PayrollFlowTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 10:00:00');
        $this->manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $this->hr = User::factory()->create()->assignRole(Roles::HR);
        app(FxRateService::class)->set('CNY', '2026-10-01', '0.140000', $this->manager);
    }

    /** A write PostgreSQL must refuse, inside a savepoint so the test's transaction survives. */
    private function assertRefused(callable $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('the database accepted a write it must refuse');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    private function employee(string $name, string $joined, string $base, string $allowances = '0'): Employee
    {
        $employee = app(EmployeeService::class)->create(['name' => $name, 'joined_on' => $joined, 'salary_currency' => 'CNY'], $this->hr);
        app(EmployeeService::class)->addSalary($employee, ['effective_from' => $joined, 'base_salary' => $base, 'allowances' => $allowances], $this->manager);

        return $employee;
    }

    public function test_a_month_is_prepared_approved_posted_and_paid(): void
    {
        // October 2026, Monday–Friday: 22 working days (no holidays entered).
        $full = $this->employee('Li Wei', '2025-03-01', '8700.00', '500.00');
        $joiner = $this->employee('Chen Jing', '2026-10-16', '6600.00');
        BusinessSetting::query()->updateOrCreate(['id' => 1], ['si_employee_percent' => '10.5', 'si_employer_percent' => '20', 'hf_employee_percent' => '5', 'hf_employer_percent' => '5']);

        // Two hours of overtime and one absence for Li Wei; one approved day of unpaid leave.
        app(AttendanceService::class)->record($full, ['work_date' => '2026-10-08', 'status' => 'present', 'overtime_hours' => '2'], $this->hr);
        app(AttendanceService::class)->record($full, ['work_date' => '2026-10-09', 'status' => 'absent'], $this->hr);
        $leave = app(LeaveService::class)->request($full, ['leave_type' => 'unpaid', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-12'], $this->hr);
        app(LeaveService::class)->approve($leave, $this->manager);

        $run = app(PayrollService::class)->prepare(['period' => '2026-10', 'currency' => 'CNY'], $this->hr);
        $this->assertTrue($run->isDraft());
        $this->assertStringStartsWith('DRAFT-', $run->number);

        $li = $run->lines()->where('employee_id', $full->id)->sole();
        $this->assertSame('8700.0000', $li->base_salary);
        $this->assertSame('500.0000', $li->allowances);
        $this->assertSame('2.00', $li->overtime_hours);
        // 8700 × 2 h × 1.5 ÷ (21.75 × 8) = 150.00
        $this->assertSame('150.0000', $li->overtime_pay);
        $this->assertSame('2.00', $li->unpaid_days);
        // 2 × 8700 ÷ 21.75 = 800.00
        $this->assertSame('800.0000', $li->unpaid_deduction);
        // 8700 + 500 + 150 − 800
        $this->assertSame('8550.0000', $li->fresh()->gross);
        $this->assertSame('913.5000', $li->social_insurance_employee);
        $this->assertSame('435.0000', $li->housing_fund_employee);
        $this->assertSame('7201.5000', $li->fresh()->net);

        // The joiner (from Friday the 16th) is paid for 11 of 22 working days: 6600 × 11 ÷ 22 = 3300.
        $this->assertSame('3300.0000', $run->lines()->where('employee_id', $joiner->id)->sole()->base_salary);

        // Tax is entered per line (Q-H4).
        app(PayrollService::class)->updateLine($li, ['income_tax' => '120.50', 'bonus' => '300'], $this->hr);
        $this->assertSame('7381.0000', $li->fresh()->net);

        // HR prepares; only a manager approves.
        try {
            app(PayrollService::class)->approve($run, $this->hr);
            $this->fail('HR must not approve payroll');
        } catch (AuthorizationException) {
        }
        $run = app(PayrollService::class)->approve($run, $this->manager);
        $this->assertSame('PR-2026-0001', $run->number);

        // Posted on the month end at that day's rate, balanced.
        $accountant = User::factory()->create()->assignRole(Roles::ACCOUNTANT);
        $entry = app(PostingService::class)->postPayroll($run, $accountant);
        $this->assertSame('2026-10-31', $entry->entry_date->toDateString());
        $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->with('account')->get();
        $this->assertSame(0, bccomp((string) $lines->sum('debit'), (string) $lines->sum('credit'), 4));
        $byCode = $lines->groupBy(fn ($l) => $l->account->code);
        $totals = app(PayrollQueries::class)->totals($run);
        $this->assertSame(bcadd($totals['gross'], '0', 4), $byCode['6201']->sole()->amount_currency);
        $this->assertSame(bcadd($totals['net'], '0', 4), $byCode['2402']->sole()->amount_currency);
        $this->assertSame('120.5000', $byCode['2404']->sole()->amount_currency);
        $this->assertArrayHasKey('6203', $byCode->all());
        $this->assertArrayHasKey('2403', $byCode->all());

        // Paid from an RMB account; the run shows what is still to pay.
        $rmb = MoneyAccount::factory()->rmb()->create();
        $payment = app(PaymentService::class)->record(['kind' => 'salary_payment', 'paid_on' => '2026-11-05', 'money_account_id' => $rmb->id,
            'amount' => $totals['net'], 'payroll_run_id' => $run->id], $this->hr);
        app(PaymentService::class)->approve($payment, $this->manager); // recorded by HR, approved by a manager (B16)
        $this->assertSame(0, bccomp('0', app(PayrollQueries::class)->unpaid($run), 4));
        $paymentEntry = app(PostingService::class)->postPayment($payment->fresh(), $accountant);
        $this->assertSame('2402', JournalLine::query()->where('journal_entry_id', $paymentEntry->id)->where('debit', '>', 0)->sole()->account->code);

        // Voiding needs the payment reversed and the entry reversed first.
        try {
            app(PayrollService::class)->void($run, $this->manager, 'Wrong month');
            $this->fail('a paid run must not be voided');
        } catch (ValidationException $e) {
            $this->assertSame(__('Reverse the salary payments of :number first (on the Payments screen).', ['number' => $run->number]), $e->errors()['run'][0]);
        }
    }

    public function test_the_month_takes_one_run_per_currency_and_an_approved_run_never_changes(): void
    {
        $this->employee('Li Wei', '2025-03-01', '8000');
        $run = app(PayrollService::class)->prepare(['period' => '2026-10', 'currency' => 'CNY'], $this->hr);

        $this->expectException(ValidationException::class);
        try {
            app(PayrollService::class)->prepare(['period' => '2026-10', 'currency' => 'CNY'], $this->hr);
        } finally {
            $run = app(PayrollService::class)->approve($run, $this->manager);
            $this->assertRefused(fn () => DB::table('payroll_lines')->where('payroll_run_id', $run->id)->update(['bonus' => 1]));
            $this->assertRefused(fn () => DB::table('payroll_runs')->where('id', $run->id)->update(['pay_date' => '2026-11-10']));
            $this->assertRefused(fn () => DB::table('payroll_runs')->where('id', $run->id)->delete());
        }
    }

    /** B05: salaries paid against a run never come to more than its unpaid net pay. */
    public function test_a_salary_payment_cannot_exceed_the_runs_unpaid_net_pay(): void
    {
        app()->setLocale('en');
        $this->employee('Li Wei', '2025-03-01', '8000');
        $run = app(PayrollService::class)->approve(app(PayrollService::class)->prepare(['period' => '2026-10'], $this->hr), $this->manager);
        $net = app(PayrollQueries::class)->totals($run)['net'];
        $rmb = MoneyAccount::factory()->rmb()->create();
        $pay = fn (string $amount) => app(PaymentService::class)->record(['kind' => 'salary_payment', 'paid_on' => '2026-11-05', 'money_account_id' => $rmb->id, 'amount' => $amount, 'payroll_run_id' => $run->id], $this->manager);

        $this->assertThrows(fn () => $pay('99999'), ValidationException::class, 'left to pay. A payment against it cannot be more.');
        $pay('100');
        $this->assertThrows(fn () => $pay(bcsub($net, '99.99', 4)), ValidationException::class);
        $pay(bcsub($net, '100', 4));
        // Both await approval and still count: nothing is left to pay.
        $this->assertThrows(fn () => $pay('0.01'), ValidationException::class, 'CNY 0.00 left to pay');
    }

    public function test_salaries_are_hidden_from_who_does_not_see_payroll(): void
    {
        $employee = $this->employee('Li Wei', '2025-03-01', '8000');
        $run = app(PayrollService::class)->approve(app(PayrollService::class)->prepare(['period' => '2026-10'], $this->hr), $this->manager);
        $rmb = MoneyAccount::factory()->rmb()->create();
        app(PaymentService::class)->record(['kind' => 'salary_payment', 'paid_on' => '2026-11-05', 'money_account_id' => $rmb->id, 'amount' => '100', 'payroll_run_id' => $run->id], $this->manager);

        $sales = User::factory()->create()->assignRole(Roles::SALES);
        $this->actingAs($sales)->get(route('payments.index'))->assertOk()->assertDontSee($run->number);
        $this->actingAs($sales)->get(route('employees.show', $employee))->assertForbidden();
        $this->actingAs($this->manager)->get(route('payments.index'))->assertOk()->assertSee(__('Salary payment'));
    }
}
