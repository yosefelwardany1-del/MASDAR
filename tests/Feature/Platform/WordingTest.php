<?php

namespace Tests\Feature\Platform;

use App\Models\Account;
use App\Models\PayrollRun;
use App\Models\User;
use App\Security\Roles;
use App\Services\Configuration\BusinessSettingsService;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\PayrollService;
use App\Support\IO\ImportResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B33: the wording table — each message says what happened, in a sentence that reads right. */
class WordingTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function user(string $role): User
    {
        app()->setLocale('en');

        return User::factory()->create(['locale' => 'en'])->assignRole($role);
    }

    public function test_a_second_payroll_for_a_month_names_the_month_and_links_to_the_first(): void
    {
        $this->travelTo('2026-11-20 10:00:00');
        $manager = $this->user(Roles::MANAGER);
        $hr = $this->user(Roles::HR);
        $employee = app(EmployeeService::class)->create(['name' => 'Li Wei', 'joined_on' => '2025-03-01', 'salary_currency' => 'USD'], $hr);
        app(EmployeeService::class)->addSalary($employee, ['effective_from' => '2025-03-01', 'base_salary' => '1200'], $manager);
        $run = app(PayrollService::class)->prepare(['period' => '2026-10', 'currency' => 'USD'], $hr);
        $this->assertStringStartsWith('DRAFT-', $run->number);

        $this->actingAs($hr)->from(route('payroll.index'))->post(route('payroll.store'), ['period' => '2026-10', 'currency' => 'USD'])
            ->assertRedirect(route('payroll.index'))
            ->assertSessionHasErrors(['period' => 'There is already a draft USD payroll for October 2026.'])->assertSessionHas('existing_payroll', $run->id);
        $this->actingAs($hr)->followingRedirects()->from(route('payroll.index'))->post(route('payroll.store'), ['period' => '2026-10', 'currency' => 'USD'])
            ->assertSee('There is already a draft USD payroll for October 2026.')->assertSee(route('payroll.show', $run), false)->assertDontSee($run->number);
        $this->assertSame(1, PayrollRun::count());
    }

    public function test_an_oversized_upload_says_the_limit(): void
    {
        app()->setLocale('en');
        $big = new UploadedFile(base_path('composer.json'), 'scan.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
        $limit = rtrim(rtrim(number_format(UploadedFile::getMaxFilesize() / 1048576, 1, '.', ''), '0'), '.');

        $errors = validator(['files' => [$big]], ['files.*' => ['file', 'max:10240']])->errors();
        $this->assertSame("This file is bigger than the {$limit} MB limit.", $errors->first('files.0'));
        $broken = new UploadedFile(base_path('composer.json'), 'scan.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL, true);
        $this->assertSame('This file could not be uploaded. Try again.', validator(['file' => $broken], ['file' => 'file'])->errors()->first('file'));
    }

    public function test_the_roles_page_counts_the_roles_it_lists(): void
    {
        $admin = $this->user(Roles::SYSTEM_ADMIN);
        $count = Role::count();
        $this->actingAs($admin)->get(route('roles.index'))->assertOk()->assertSee("{$count} roles. A user may hold several")->assertDontSee('Ten fixed roles');
    }

    public function test_the_purchase_orders_page_states_one_rule(): void
    {
        $manager = $this->user(Roles::MANAGER);
        $this->actingAs($manager)->get(route('supplier-orders.index'))->assertOk()
            ->assertSee('made from a confirmed PI, followed step by step')->assertSee('Purchase orders are made from a confirmed PI and appear here')
            ->assertDontSee('from an issued PI');
        app(BusinessSettingsService::class)->update(['orders_from_issued_pi' => true]);
        $this->actingAs($manager)->get(route('supplier-orders.index'))->assertOk()
            ->assertSee('made from an issued PI, followed step by step')->assertSee('Purchase orders are made from an issued PI and appear here')
            ->assertDontSee('from a confirmed PI');
    }

    public function test_the_account_ledger_reads_an_asset_account(): void
    {
        $manager = $this->user(Roles::MANAGER);
        $this->actingAs($manager)->get(route('finance-reports.account-ledger', ['account_id' => Account::where('code', '1101')->value('id')]))->assertOk()
            ->assertSee('positive when normal for an asset account')->assertDontSee('for a Asset account');
        $this->actingAs($manager)->get(route('finance-reports.account-ledger', ['account_id' => Account::where('code', '6101')->value('id')]))->assertOk()
            ->assertSee('positive when normal for an expense account');
    }

    public function test_the_import_preview_counts_in_the_singular_and_plural(): void
    {
        app()->setLocale('en');
        $this->assertSame('0 new, 0 updates, 1 error (1 row)', ImportResult::summary(0, 0, 1, 1));
        $this->assertSame('2 new, 1 update, 3 errors (6 rows)', ImportResult::summary(2, 1, 3, 6));
        app()->setLocale('ar');
        $this->assertSame('0 جديد، 0 تحديث، خطأ واحد (صف واحد)', ImportResult::summary(0, 0, 1, 1));
    }
}
