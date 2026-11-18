<?php

namespace Tests\Feature\Books;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\ExpenseClaim;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\User;
use App\Security\Roles;
use App\Services\Accounting\BudgetQueries;
use App\Services\Accounting\BudgetService;
use App\Services\Accounting\FixedAssetService;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\Revaluation;
use App\Services\Finance\BankReconciliationService;
use App\Services\Finance\ExpenseClaimService;
use App\Services\Finance\FxRateService;
use App\Services\Finance\PaymentQueries;
use App\Services\Finance\PaymentService;
use App\Services\Reports\AgeingQueries;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.8, batch C: bank reconciliation, revaluation, opening balances, expense claims, budgets, fixed assets. */
class BooksCompletionTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $this->manager = User::where('email', 'manager@example.test')->sole();
        $this->accountant = User::where('email', 'accountant@example.test')->sole();
    }

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

    public function test_a_bank_statement_is_imported_and_matched_to_the_payments(): void
    {
        $usd = MoneyAccount::where('name', 'HSBC Hong Kong — USD')->sole();
        $d = fn (int $daysAgo) => now()->subDays($daysAgo)->format('d/m/Y');
        $csv = "Date,Details,Amount,Balance\n"
            .now()->subMonthNoOverflow()->startOfMonth()->format('d/m/Y').",OWNERS FUNDS,\"10,000.00\",10000.00\n"
            .$d(18).",TT GULF HOME TRADING,3218.40,13218.40\n"
            .$d(17).",TRANSFER TO MAINLAND,-1400.00,11818.40\n"
            .$d(3).",SERVICE CHARGE,-15.00,11803.40\n";
        $this->actingAs($this->accountant)->post(route('bank-reconciliation.store'), [
            'money_account_id' => $usd->id, 'file' => UploadedFile::fake()->createWithContent('hsbc.csv', $csv), 'date_format' => 'd/m/Y',
            'layout' => 'one', 'col_date' => 1, 'col_description' => 2, 'col_amount' => 3, 'col_balance' => 4, 'skip_header' => '1',
        ])->assertRedirect();
        $statement = BankStatement::sole();
        $this->assertSame([4, '-15.0000'], [$statement->lines()->count(), (string) $statement->lines()->reorder('line_no', 'desc')->first()->amount]);
        $this->assertRefused(fn () => DB::table('bank_statement_lines')->where('bank_statement_id', $statement->id)->update(['amount' => 1]));

        $service = app(BankReconciliationService::class);
        $this->assertSame(3, $service->matchSuggested($statement, $this->accountant), 'the owners\' funds, the receipt and the transfer; the bank fee has no payment');
        $fee = BankStatementLine::where('amount', '-15.0000')->sole();
        $receipt = Payment::where('kind', 'client_receipt')->first();
        $this->assertThrows(fn () => $service->match($fee, $receipt, $this->accountant), ValidationException::class);

        $this->actingAs($this->accountant)->get(route('bank-reconciliation.show', $statement))->assertOk()
            ->assertSee(__('Record it as a payment'))->assertSee('11,803.40');
        $this->actingAs($this->accountant)->delete(route('bank-reconciliation.destroy', $statement))->assertSessionHasErrors('statement');
        $line = BankStatementLine::where('amount', '3218.4000')->sole();
        $service->unmatch($line, $this->accountant);
        $this->assertNull($line->fresh()->match);
    }

    public function test_the_revaluation_restates_foreign_balances_at_the_rate_and_carries_on(): void
    {
        $factory = Party::where('name', 'Yiwu Bright Housewares Factory')->sole();
        // Billed 16,992 CNY at 0.14, paid 5,000: 11,992 CNY owed, carried at 1,678.88 USD.
        app(FxRateService::class)->set('CNY', now()->toDateString(), '0.150000', $this->manager);
        $rows = collect(app(Revaluation::class)->preview(now()->toDateString()));
        $payable = $rows->first(fn ($r) => $r['account']->code === '2201' && $r['party_id'] === $factory->id);
        $this->assertSame(['-11992.0000', '-1678.8800', '-1798.8000', '-119.9200'], [$payable['balance'], $payable['carried'], $payable['revalued'], $payable['difference']]);

        $entry = app(PostingService::class)->postRevaluation(now()->toDateString(), $this->manager);
        $this->assertSame('fx_revaluation', $entry->event);
        $this->assertSame(0, bccomp((string) $entry->lines()->sum('debit'), (string) $entry->lines()->sum('credit'), 4));
        $this->assertNull(collect(app(Revaluation::class)->preview(now()->toDateString()))->first(fn ($r) => $r['account']->code === '2201' && $r['party_id'] === $factory->id), 'nothing left to revalue');

        // Reversing it works although 1201 takes RMB only.
        app(PostingService::class)->reverse($entry, $this->manager);
        $this->assertNotNull(collect(app(Revaluation::class)->preview(now()->toDateString()))->first(fn ($r) => $r['account']->code === '2201'));
        $this->actingAs($this->manager)->get(route('accounting.revaluation'))->assertOk();
    }

    public function test_opening_balances_become_balanced_drafts_and_age_from_their_own_dates(): void
    {
        $client = Party::where('name', 'Nile Kitchenware Co.')->sole();
        $old = now()->subDays(75)->toDateString();
        $drafts = app(OpeningBalanceService::class)->import([
            ['date' => $old, 'account' => '1401', 'party' => $client->code, 'currency' => 'USD', 'amount' => '2500', 'rate' => '', 'memo' => 'Old invoice'],
            ['date' => now()->toDateString(), 'account' => '1102', 'party' => '', 'currency' => 'HKD', 'amount' => '7800', 'rate' => '0.128', 'memo' => 'HKD account'],
        ], $this->accountant);
        $this->assertCount(2, $drafts);
        $first = $drafts[0]->fresh('lines.account');
        $this->assertSame(['opening_balance', 'draft'], [$first->event, $first->status]);
        $this->assertSame('2500.0000', (string) $first->lines->firstWhere('account.code', '3901')->credit);
        $this->assertThrows(fn () => app(OpeningBalanceService::class)->import([['date' => 'x', 'account' => '9999', 'party' => '', 'currency' => '', 'amount' => 'a', 'rate' => '', 'memo' => '']], $this->accountant), ValidationException::class);

        app(PostingService::class)->post($drafts[0], $this->manager);
        $row = collect(app(AgeingQueries::class)->report('receivable', now()->toDateString(), $this->manager)['rows'])->firstWhere('party.id', $client->id);
        $this->assertSame('2500.0000', $row['buckets'][2], '75 days old: 61–90');
        $this->actingAs($this->accountant)->get(route('accounting.opening.template'))->assertOk()->assertSee('date,account,party');
    }

    public function test_an_expense_claim_is_submitted_approved_booked_and_paid_back(): void
    {
        $staff = User::where('email', 'merchandiser@example.test')->sole();
        $service = app(ExpenseClaimService::class);
        $claim = $service->create(['currency' => 'CNY', 'title' => 'Ningbo port visit'], $staff);
        $service->addLine($claim, ['spent_on' => now()->toDateString(), 'category' => 'travel', 'description' => 'High-speed train Yiwu–Ningbo', 'amount' => '120'], $staff);
        $service->addLine($claim, ['spent_on' => now()->toDateString(), 'category' => 'meals', 'description' => 'Lunch with the forwarder', 'amount' => '280'], $staff);
        $this->assertSame('400.0000', $claim->total());

        // Not paid before it is approved, and nobody decides their own claim.
        $rmb = MoneyAccount::where('name', 'Yiwu bank — RMB')->sole();
        $this->assertThrows(fn () => app(PaymentService::class)->record(['kind' => 'claim_payment', 'paid_on' => now()->toDateString(), 'money_account_id' => $rmb->id, 'amount' => '400', 'expense_claim_id' => $claim->id], $this->manager), ValidationException::class);
        $service->submit($claim, $staff);
        $this->assertThrows(fn () => $service->addLine($claim->fresh(), ['spent_on' => now()->toDateString(), 'category' => 'other', 'description' => 'x', 'amount' => '1'], $staff), ValidationException::class);
        $this->assertRefused(fn () => DB::table('expense_claim_decisions')->insert(['expense_claim_id' => $claim->id, 'decision' => 'approved', 'user_id' => $staff->id]));
        $service->decideAs($claim->fresh(), 'approved', $this->manager, null);

        $entry = app(PostingService::class)->postExpenseClaim($claim->fresh(), $this->accountant);
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($l) => $l->account->code);
        $this->assertSame(['16.8000', '39.2000', '56.0000'], [(string) $lines['6502']->debit, (string) $lines['6599']->debit, (string) $lines['2405']->credit]);

        $payment = app(PaymentService::class)->record(['kind' => 'claim_payment', 'paid_on' => now()->toDateString(), 'money_account_id' => $rmb->id, 'amount' => '400', 'expense_claim_id' => $claim->id], $this->accountant);
        $payment = Payment::find($payment->id);
        if (! app(PaymentQueries::class)->isEffective($payment)) {
            app(PaymentService::class)->approve($payment, $this->manager);
        }
        $paid = app(PostingService::class)->postPayment($payment, $this->accountant);
        $this->assertSame('2405', $paid->lines()->with('account')->get()->first(fn ($l) => $l->debit > 0)->account->code);

        $this->actingAs($staff)->get(route('my-expenses.show', $claim))->assertOk()->assertSee(__('Paid back'));
        $this->actingAs($staff)->get(route('expense-claims.index'))->assertForbidden();
        $other = User::factory()->create()->assignRole(Roles::STAFF);
        $this->actingAs($other)->get(route('my-expenses.show', $claim))->assertNotFound();
        $this->assertSame(1, ExpenseClaim::count());
    }

    public function test_a_budget_compares_with_the_posted_books(): void
    {
        $budget = app(BudgetService::class)->create(['name' => 'Main budget', 'year' => (int) now()->year], $this->manager);
        $salaries = Account::where('code', '6201')->sole();
        $month = (int) now()->subMonthNoOverflow()->month;
        app(BudgetService::class)->saveAmounts($budget, [$salaries->id => [$month => '6000', 13 => '1']], $this->manager);
        $this->assertSame(1, $budget->lines()->count());

        $report = app(BudgetQueries::class)->compare($budget->fresh('lines'));
        $row = collect($report['expense'])->firstWhere('account.code', '6201');
        $this->assertSame('6000.0000', $row['budget']);
        $this->assertTrue(bccomp($row['actual'], '0', 4) > 0, 'last month\'s payroll is posted');
        $this->actingAs($this->manager)->get(route('budgets.show', $budget))->assertOk()->assertSee('6201');
        $this->actingAs($this->manager)->get(route('budgets.show', ['budget' => $budget, 'edit' => 1]))->assertOk();
    }

    public function test_fixed_assets_depreciate_monthly_once_and_down_to_their_residual_value(): void
    {
        $service = app(FixedAssetService::class);
        $start = now()->subMonthsNoOverflow(2)->startOfMonth();
        $asset = $service->create([
            'name' => 'Laptop for the Yiwu office', 'acquired_on' => $start->toDateString(), 'depreciation_starts_on' => $start->toDateString(),
            'cost' => '1300', 'residual_value' => '100', 'useful_life_months' => '12',
            'asset_account_id' => Account::where('code', '1801')->value('id'), 'depreciation_account_id' => Account::where('code', '1802')->value('id'),
            'expense_account_id' => Account::where('code', '6601')->value('id'),
        ], $this->accountant);
        $this->assertSame(['FA-0001', '100.0000'], [$asset->code, $asset->monthlyCharge()]);

        $entry = $service->depreciate($start->format('Y-m'), $this->accountant);
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($l) => $l->account->code);
        $this->assertSame(['100.0000', '100.0000'], [(string) $lines['6601']->debit, (string) $lines['1802']->credit]);
        $this->assertSame($start->copy()->endOfMonth()->toDateString(), $entry->entry_date->toDateString());
        $this->assertThrows(fn () => $service->depreciate($start->format('Y-m'), $this->accountant), ValidationException::class);
        $this->assertThrows(fn () => $service->update($asset->fresh(), ['cost' => '2000'] + $asset->fresh()->only(['name', 'residual_value', 'useful_life_months', 'asset_account_id', 'depreciation_account_id', 'expense_account_id'])
            + ['acquired_on' => $start->toDateString(), 'depreciation_starts_on' => $start->toDateString()], $this->accountant), ValidationException::class);
        $this->assertSame('1200.0000', $asset->fresh()->bookValue());

        $service->dispose($asset->fresh(), now()->toDateString(), 'Sold to staff', $this->accountant);
        $this->actingAs($this->accountant)->get(route('fixed-assets.index'))->assertOk()->assertSee('FA-0001');
        $this->assertSame(1, FixedAsset::count());
        $this->assertTrue(JournalEntry::where('event', 'depreciation')->exists());
    }
}
