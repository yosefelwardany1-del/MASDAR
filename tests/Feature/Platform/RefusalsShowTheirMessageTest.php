<?php

namespace Tests\Feature\Platform;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\Party;
use App\Models\User;
use App\Security\Roles;
use App\Services\Accounting\BudgetService;
use App\Services\Accounting\FixedAssetService;
use App\Services\Accounting\PostingService;
use App\Services\Claims\ClaimService;
use App\Services\Finance\ExpenseClaimService;
use App\Services\Finance\FxRateService;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\PayrollService;
use App\Services\Quotations\QuotationService;
use App\Services\ServiceInvoices\ServiceInvoiceService;
use App\Services\Sourcing\SourcingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * The brief's "refused without an error message": each refusal is said in words, next to the
 * field it is about — not only at the top of the page, and in the form that was sent.
 */
class RefusalsShowTheirMessageTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-19 10:00:00');
        app()->setLocale('en');
        $this->manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
    }

    /**
     * Send $data from $page; it must be refused on $field, and the page it returns to must show the
     * message in that field's feedback (invalid-feedback or the form's own error line).
     */
    private function assertShownAtField(string $page, string $method, string $url, array $data, string $field, ?User $as = null): string
    {
        $as ??= $this->manager;
        $this->actingAs($as)->from($page)->call($method, $url, $data)->assertSessionHasErrors($field);
        $message = session('errors')->first($field);
        $html = $this->actingAs($as)->from($page)->followingRedirects()->call($method, $url, $data)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/class="[^"]*(invalid-feedback|text-danger|app-field-error)[^"]*"[^>]*>\s*'.preg_quote(e($message), '/').'/u', $html,
            "\"{$message}\" ({$field}) is not shown next to its field on {$page}");

        return $message;
    }

    private function client(): Party
    {
        return Party::factory()->client()->create();
    }

    public function test_a_quotation_line(): void
    {
        $quotations = app(QuotationService::class);
        $q = $quotations->create(['client_id' => $this->client()->id, 'currency' => 'USD', 'quoted_on' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString()], $this->manager);
        $page = route('quotations.show', $q);
        $url = route('quotations.lines.store', $q);
        $this->assertShownAtField($page, 'POST', $url, ['_form' => 'qn'], 'unit_price');
        $this->assertShownAtField($page, 'POST', $url, ['_form' => 'qn', 'description' => 'Jar', 'unit_price' => '-1'], 'unit_price');
        $this->assertShownAtField($page, 'POST', $url, ['_form' => 'qn', 'description' => 'Jar', 'unit_price' => '1', 'qty_per_carton' => '12', 'cartons' => '-2'], 'cartons');

        // Only the form that was sent shows it: the line already there does not.
        $line = $quotations->addLine($q, ['description' => 'Lid', 'unit' => 'pcs', 'unit_price' => '1'], $this->manager);
        $html = $this->actingAs($this->manager)->from($page)->followingRedirects()->post($url, ['_form' => 'qn', 'description' => 'Jar', 'unit_price' => '-1'])->getContent();
        $this->assertSame(1, substr_count($html, 'is-invalid'), 'one field marked, in the form that was sent');
        // A line's own form: its edit row opens with the message under the field.
        $html = $this->actingAs($this->manager)->from($page)->followingRedirects()
            ->put(route('quotations.lines.update', [$q, $line]), ['_form' => 'ql'.$line->id, 'description' => 'Lid', 'unit' => 'pcs', 'unit_price' => '-1'])->getContent();
        $this->assertStringContainsString('<tr id="ql-'.$line->id.'" class=" app-q-editrow">', $html);
        $this->assertSame(1, substr_count($html, 'is-invalid'));
    }

    public function test_a_sample(): void
    {
        $page = route('samples.create');
        $this->assertShownAtField($page, 'POST', route('samples.store'), ['description' => 'Jar', 'quantity' => '-3'], 'quantity');
        $this->assertShownAtField($page, 'POST', route('samples.store'), ['description' => 'Jar', 'requested_on' => '2026-11-10', 'needed_by' => '2026-11-01'], 'needed_by');
    }

    public function test_a_factory_quote(): void
    {
        $rfq = app(SourcingService::class)->create(['description' => 'Glass jar 500ml'], $this->manager);
        $supplier = Party::factory()->supplier()->create();
        $page = route('sourcing.show', $rfq);
        $url = route('sourcing.quotes.store', $rfq);
        $base = ['supplier_id' => $supplier->id, 'unit_price' => '3', 'currency' => 'CNY'];
        $this->assertShownAtField($page, 'POST', $url, ['unit_price' => '-3'] + $base, 'unit_price');
        $this->assertShownAtField($page, 'POST', $url, ['lead_time_days' => '-5'] + $base, 'lead_time_days');
        $this->assertShownAtField($page, 'POST', $url, ['received_on' => '2026-11-10', 'valid_until' => '2026-11-01'] + $base, 'valid_until');
    }

    public function test_a_service_invoice(): void
    {
        $client = $this->client();
        $this->assertShownAtField(route('service-invoices.create'), 'POST', route('service-invoices.store'),
            ['client_id' => $client->id, 'currency' => 'USD', 'line_category' => 'inspection', 'line_description' => 'QC visit', 'line_amount' => '-50'], 'line_amount');
        $invoice = app(ServiceInvoiceService::class)->createDraft(['client_id' => $client->id, 'currency' => 'USD'], $this->manager);
        $this->assertShownAtField(route('service-invoices.show', $invoice), 'POST', route('service-invoices.lines.store', $invoice),
            ['category' => 'inspection', 'description' => 'QC visit', 'quantity' => '-1', 'unit_price' => '50'], 'quantity');
    }

    public function test_a_claim_agreed_with_a_negative_amount(): void
    {
        $claim = app(ClaimService::class)->open(['kind' => 'defect', 'client_id' => $this->client()->id, 'description' => 'Chipped rims', 'claimed_amount' => '100', 'currency' => 'USD'], $this->manager);
        $this->assertShownAtField(route('claims.show', $claim), 'POST', route('claims.move', $claim), ['status' => 'agreed', 'agreed_amount' => '-5'], 'agreed_amount');
    }

    public function test_an_expense_receipt(): void
    {
        $claim = app(ExpenseClaimService::class)->create(['currency' => 'CNY', 'title' => 'Trip'], $this->manager);
        $this->assertShownAtField(route('my-expenses.show', $claim), 'POST', route('my-expenses.lines.store', $claim),
            ['spent_on' => '2026-11-10', 'category' => 'travel', 'description' => 'Train', 'amount' => '-50'], 'amount');
    }

    public function test_budget_amounts(): void
    {
        $budget = app(BudgetService::class)->create(['name' => 'Main budget', 'year' => 2026], $this->manager);
        $account = Account::where('code', '6201')->value('id');
        $page = route('budgets.show', ['budget' => $budget, 'edit' => 1]);
        $this->assertShownAtField($page, 'PUT', route('budgets.update', $budget), ['amounts' => [$account => [1 => '-100']]], "amounts.{$account}.1");
        $this->assertShownAtField($page, 'PUT', route('budgets.update', $budget), ['amounts' => [$account => [2 => 'abc']]], "amounts.{$account}.2");
    }

    public function test_a_leave_allowance(): void
    {
        $employee = app(EmployeeService::class)->create(['name' => 'Ahmed Saleh', 'joined_on' => '2024-01-08'], $this->manager);
        $this->assertShownAtField(route('employees.show', $employee), 'POST', route('employees.allowances.store', $employee),
            ['year' => 2026, 'leave_type' => 'annual', 'days' => '-2'], 'days');
    }

    public function test_a_fixed_asset(): void
    {
        $accounts = ['asset_account_id' => Account::where('code', '1801')->value('id'), 'depreciation_account_id' => Account::where('code', '1802')->value('id'),
            'expense_account_id' => Account::where('code', '6601')->value('id')];
        $base = ['name' => 'Laptop', 'acquired_on' => '2026-06-10', 'cost' => '1300', 'residual_value' => '100', 'useful_life_months' => '12'] + $accounts;
        $this->assertShownAtField(route('fixed-assets.create'), 'POST', route('fixed-assets.store'), ['depreciation_starts_on' => '2026-05-01'] + $base, 'depreciation_starts_on');

        $asset = app(FixedAssetService::class)->create(['depreciation_starts_on' => '2026-07-01'] + $base, $this->manager);
        $this->assertShownAtField(route('fixed-assets.show', $asset), 'POST', route('fixed-assets.dispose', $asset), ['disposed_on' => '2026-06-01', 'disposal_note' => 'Sold'], 'disposed_on');
    }

    public function test_voiding_a_posted_payroll_run(): void
    {
        $hr = User::factory()->create(['locale' => 'en'])->assignRole(Roles::HR);
        app(FxRateService::class)->set('CNY', '2026-10-01', '0.140000', $this->manager);
        $employee = app(EmployeeService::class)->create(['name' => 'Li Wei', 'joined_on' => '2025-03-01', 'salary_currency' => 'CNY'], $hr);
        app(EmployeeService::class)->addSalary($employee, ['effective_from' => '2025-03-01', 'base_salary' => '8700'], $this->manager);
        $run = app(PayrollService::class)->prepare(['period' => '2026-10', 'currency' => 'CNY'], $hr);
        $run = app(PayrollService::class)->approve($run, $this->manager);
        app(PostingService::class)->postPayroll($run, $this->manager);

        // The refusal was folded inside the closed "Void this run" box: it opens now.
        $message = $this->assertShownAtField(route('payroll.show', $run), 'POST', route('payroll.void', $run), ['void_reason' => 'Wrong month'], 'run');
        $this->assertStringContainsString('Reverse journal entry', $message);
        $html = $this->actingAs($this->manager)->from(route('payroll.show', $run))->followingRedirects()->post(route('payroll.void', $run), ['void_reason' => 'Wrong month'])->getContent();
        $this->assertMatchesRegularExpression('/<details class="app-hr-inlineform border-top"\s+open\s*>/', $html);
    }

    public function test_lists_and_options_mark_the_field(): void
    {
        $options = BusinessSetting::current()->only(['pi_currency', 'supplier_currency', 'book_currency', 'default_deposit_percent', 'pi_terms_template', 'eta_warning_days']);
        $this->assertShownAtField(route('configuration.index', ['tab' => 'options']), 'PUT', route('configuration.options.update'), ['step_warning_days' => '-2'] + $options, 'step_warning_days');
    }
}
