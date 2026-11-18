<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\ErpNotification;
use App\Models\User;
use App\Security\Roles;
use App\Services\Dashboard\AttentionQueries;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\LeaveQueries;
use App\Services\Hr\LeaveService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.7: leave requests, decisions and balances; employee documents and their expiry. */
class LeaveAndDocumentsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        $this->manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $this->hr = User::factory()->create()->assignRole(Roles::HR);
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

    private function employee(?User $user = null): Employee
    {
        return app(EmployeeService::class)->create(['name' => 'Ahmed Saleh', 'joined_on' => '2024-01-08', 'user_id' => $user?->id], $this->hr);
    }

    public function test_an_employee_requests_their_own_leave_and_a_manager_decides(): void
    {
        $staff = User::factory()->create()->assignRole(Roles::STAFF);
        $employee = $this->employee($staff);
        $leave = app(LeaveService::class);

        // Mon 23 – Wed 25 November: three working days, counted from the dates.
        $request = $leave->request($employee, ['leave_type' => 'annual', 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-25', 'reason' => 'Family visit'], $staff);
        $this->assertSame('3.00', (string) $request->days);
        $this->assertSame('pending', $request->status());
        $this->assertSame(1, ErpNotification::where('user_id', $this->manager->id)->count(), 'the approver is told');
        $this->assertSame(0, ErpNotification::where('user_id', $staff->id)->count());

        // The balance counts pending days: 5 a year, 3 asked, 2 left — a request for 3 more is refused.
        $this->assertSame('2.00', collect(app(LeaveQueries::class)->balances($employee, 2026))->firstWhere('type', 'annual')['left']);
        $this->assertThrows(fn () => $leave->request($employee, ['leave_type' => 'annual', 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09'], $staff), ValidationException::class);
        // Overlapping dates are refused.
        $this->assertThrows(fn () => $leave->request($employee, ['leave_type' => 'sick', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-26'], $staff), ValidationException::class);

        // Nobody decides their own request, nor without leave.approve.
        $this->assertThrows(fn () => $leave->approve($request, $staff), AuthorizationException::class);
        $this->assertThrows(fn () => $leave->approve($request, $this->hr), AuthorizationException::class);

        $leave->approve($request, $this->manager);
        $this->assertSame('approved', $request->fresh()->status());
        $this->assertSame(1, ErpNotification::where('user_id', $staff->id)->count(), 'the employee is told');

        // A decided request takes no second decision; it can still be cancelled before it starts.
        $this->assertThrows(fn () => $leave->reject($request, $this->manager), ValidationException::class);
        $leave->cancel($request, $staff, 'Plans changed');
        $this->assertSame('cancelled', $request->fresh()->status());
        $this->assertSame('5.00', collect(app(LeaveQueries::class)->balances($employee, 2026))->firstWhere('type', 'annual')['left']);
    }

    public function test_the_database_keeps_requests_and_decisions(): void
    {
        $manager = $this->manager;
        $employee = $this->employee($manager);
        $request = app(LeaveService::class)->request($employee, ['leave_type' => 'sick', 'starts_on' => '2026-11-19', 'ends_on' => '2026-11-19'], $this->hr);

        // Append-only, and nobody approves their own leave even by writing to the table.
        $this->assertRefused(fn () => DB::table('leave_requests')->where('id', $request->id)->update(['days' => 0.5]));
        $this->assertRefused(fn () => DB::table('leave_decisions')->insert(['leave_request_id' => $request->id, 'decision' => 'approved', 'decided_by' => $manager->id]));
        DB::table('leave_decisions')->insert(['leave_request_id' => $request->id, 'decision' => 'rejected', 'decided_by' => $this->hr->id]);
        $this->assertRefused(fn () => DB::table('leave_decisions')->insert(['leave_request_id' => $request->id, 'decision' => 'approved', 'decided_by' => $this->hr->id]));
        // Half days only, never more days than the dates hold.
        $this->assertRefused(fn () => DB::table('leave_requests')->insert(['employee_id' => $employee->id, 'leave_type' => 'sick', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'days' => 1.5, 'requested_by' => $this->hr->id]));
        $this->assertRefused(fn () => DB::table('leave_requests')->insert(['employee_id' => $employee->id, 'leave_type' => 'sick', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'days' => 0.3, 'requested_by' => $this->hr->id]));
    }

    public function test_an_employee_code_never_changes_and_salaries_and_documents_are_append_only(): void
    {
        $employee = $this->employee();
        $this->assertSame('EMP-0001', $employee->code);
        $this->assertRefused(fn () => DB::table('employees')->where('id', $employee->id)->update(['code' => 'EMP-9']));

        $salary = app(EmployeeService::class)->addSalary($employee, ['effective_from' => '2024-01-08', 'base_salary' => '7000'], $this->manager);
        $this->assertRefused(fn () => DB::table('employee_salaries')->where('id', $salary->id)->update(['base_salary' => 9000]));
        // Salaries need payroll access: the employee file alone is not enough.
        $logistics = User::factory()->create()->assignRole(Roles::LOGISTICS);
        $this->assertThrows(fn () => app(EmployeeService::class)->addSalary($employee, ['effective_from' => '2025-01-01', 'base_salary' => '7500'], $logistics), AuthorizationException::class);
        app(EmployeeService::class)->addSalary($employee, ['effective_from' => '2025-01-01', 'base_salary' => '7500'], $this->manager);
        $this->assertSame('7000.0000', $employee->salaryOn('2024-12-31')->base_salary);
        $this->assertSame('7500.0000', $employee->salaryOn('2026-11-18')->base_salary);
    }

    public function test_a_document_expiring_soon_reaches_hr_until_it_is_renewed(): void
    {
        $employee = $this->employee();
        $service = app(EmployeeService::class);
        $service->addDocument($employee, ['kind' => 'residence_permit', 'number' => 'ZJ-1001', 'issued_on' => '2025-12-20', 'expires_on' => '2026-12-20'], $this->hr);
        $service->addDocument($employee, ['kind' => 'passport', 'number' => 'A1234567', 'issued_on' => '2020-01-01', 'expires_on' => '2030-01-01'], $this->hr);

        $attention = app(AttentionQueries::class);
        $this->assertSame(['residence_permit'], $attention->expiringDocuments()->pluck('kind')->all());
        $this->assertSame(32, (int) $attention->expiringDocuments()->first()->days_left);

        $this->artisan('masdar:daily-alerts')->assertSuccessful();
        $this->assertSame(1, ErpNotification::where('user_id', $this->hr->id)->count());

        // Renewed: the new permit supersedes the old one.
        $service->addDocument($employee, ['kind' => 'residence_permit', 'number' => 'ZJ-2002', 'issued_on' => '2026-11-15', 'expires_on' => '2028-11-14'], $this->hr);
        $this->assertCount(0, $attention->expiringDocuments());

        // Someone who has left is no longer chased.
        $service->addDocument($employee, ['kind' => 'visa', 'issued_on' => '2026-01-01', 'expires_on' => '2026-11-30'], $this->hr);
        $this->assertCount(1, $attention->expiringDocuments());
        $service->update($employee, ['left_on' => '2026-11-17'], $this->hr);
        $this->assertCount(0, $attention->expiringDocuments());
    }

    public function test_my_leave_needs_a_linked_employee_and_shows_only_ones_own(): void
    {
        $staff = User::factory()->create()->assignRole(Roles::STAFF);
        $this->actingAs($staff)->get(route('my-leave.index'))->assertOk()->assertSee(__('Your account is not linked to an employee file.'));

        $employee = $this->employee($staff);
        $this->actingAs($staff)->post(route('my-leave.store'), ['leave_type' => 'annual', 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-23'])
            ->assertRedirect(route('my-leave.index'));
        $this->assertSame(1, $employee->leaveRequests()->count());
        $this->actingAs($staff)->get(route('my-leave.index'))->assertOk()->assertSee('2026-11-23');

        // Another employee's request is not theirs to cancel.
        $other = app(EmployeeService::class)->create(['name' => 'Other', 'joined_on' => '2024-01-08'], $this->hr);
        $theirs = app(LeaveService::class)->request($other, ['leave_type' => 'annual', 'starts_on' => '2026-11-24', 'ends_on' => '2026-11-24'], $this->hr);
        $this->actingAs($staff)->post(route('my-leave.cancel', $theirs))->assertNotFound();
        $this->actingAs($staff)->get(route('leave.index'))->assertForbidden();
    }
}
