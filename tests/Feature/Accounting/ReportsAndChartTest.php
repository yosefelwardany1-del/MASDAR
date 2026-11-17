<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\PostingRule;
use App\Security\Roles;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\LedgerReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** The chart and the reports: every figure a SUM() over posted lines. */
class ReportsAndChartTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    /** A small month of business: capital, an invoice, a receipt, a bill, a payment, an expense. */
    private function aFewPostings(Party $client, Party $supplier): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);
        $this->paymentsOutNeedNoApproval();
        $this->fxRate('CNY', '2026-11-01', '0.140000');
        $usd = MoneyAccount::factory()->create();
        $rmb = MoneyAccount::factory()->rmb()->create();

        $capital = $this->posting()->createDraft(['entry_date' => '2026-10-01', 'description' => 'Share capital', 'lines' => [
            ['account_id' => $this->account('1101')->id, 'debit' => '10000'],
            ['account_id' => $this->account('3101')->id, 'credit' => '10000'],
        ]], $manager);
        $this->posting()->post($capital, $manager);

        $this->posting()->postClientInvoice($this->confirmedPi(['client_id' => $client->id]), $manager);            // 1,200 Dr 1401 / Cr 4101
        $this->posting()->postPayment($this->payment(['kind' => 'client_receipt', 'money_account_id' => $usd->id, 'party_id' => $client->id, 'amount' => '500', 'paid_on' => '2026-11-06']), $manager);
        $order = $this->supplierOrder(lines: 1);
        $order->update(['supplier_id' => $supplier->id]);
        $this->posting()->postSupplierBill($order, $manager);                                                    // 3,120 CNY × 0.14 = 436.80
        $this->posting()->postPayment($this->payment(['kind' => 'supplier_payment', 'money_account_id' => $rmb->id, 'party_id' => $supplier->id, 'amount' => '2000', 'paid_on' => '2026-11-13']), $manager); // 280
        $this->posting()->postPayment($this->payment(['kind' => 'expense', 'money_account_id' => $usd->id, 'counter_account_id' => $this->account('6101')->id, 'amount' => '300', 'paid_on' => '2026-11-14']), $manager);
    }

    public function test_the_trial_balance_totals_agree_and_the_balance_sheet_balances(): void
    {
        $client = Party::factory()->client()->create();
        $supplier = Party::factory()->supplier()->create();
        $this->aFewPostings($client, $supplier);
        $reports = app(LedgerReports::class);

        $tb = $reports->trialBalance('2026-11-01', '2026-11-30');
        $this->assertSame($tb['totals']['opening_dr'], $tb['totals']['opening_cr']);
        $this->assertSame('10000.0000', $tb['totals']['opening_dr']);
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);
        $this->assertSame($tb['totals']['closing_dr'], $tb['totals']['closing_cr']);

        $pl = $reports->profitAndLoss('2026-11-01', '2026-11-30');
        $this->assertSame('1200.0000', $pl['income']['total']);
        $this->assertSame('736.8000', $pl['expense']['total']); // 436.80 purchases + 300 rent
        $this->assertSame('463.2000', $pl['net']);

        $bs = $reports->balanceSheet('2026-11-30', '2026-01-01');
        $this->assertTrue($bs['balanced']);
        // Cash 10,000 + 500 − 300 (USD) − 280 (RMB) + receivable 700 = 10,620
        $this->assertSame('10620.0000', $bs['assets']['total']);
        $this->assertSame('156.8000', $bs['liabilities']['total']); // 436.80 − 280 owed to the supplier
        $this->assertSame('463.2000', $bs['current_year']);
        $this->assertSame('10463.2000', $bs['equity_total']);

        // A financial year starting after the postings moves the result to earlier years; it still balances.
        $later = $reports->balanceSheet('2026-11-30', '2026-11-10');
        $this->assertTrue($later['balanced']);
        $this->assertSame('-736.8000', $later['current_year']);   // the bill of the 12th and the rent of the 14th
        $this->assertSame('1200.0000', $later['earlier_years']);  // the invoice of the 5th

        $manager = $this->manager();
        foreach (['trial-balance', 'profit-and-loss', 'balance-sheet', 'cash-position'] as $report) {
            $this->actingAs($manager)->get(route("finance-reports.{$report}", ['from' => '2026-11-01', 'to' => '2026-11-30', 'as_at' => '2026-11-30']))->assertOk();
        }
        $this->actingAs($manager)->get(route('finance-reports.balance-sheet', ['as_at' => '2026-11-30']))->assertSee('The balance sheet balances.');
        $csv = $this->actingAs($manager)->get(route('finance-reports.trial-balance', ['from' => '2026-11-01', 'to' => '2026-11-30', 'format' => 'csv']));
        $csv->assertOk()->assertDownload('trial-balance-2026-11-01-2026-11-30.csv');
        $this->assertStringContainsString('1401', $csv->streamedContent());
    }

    public function test_the_party_statement_shows_a_running_balance_after_its_opening(): void
    {
        $client = Party::factory()->client()->create();
        $this->aFewPostings($client, Party::factory()->supplier()->create());
        $this->posting()->postPayment($this->payment([
            'kind' => 'client_receipt', 'money_account_id' => MoneyAccount::factory()->create()->id,
            'party_id' => $client->id, 'amount' => '200', 'paid_on' => '2026-11-20',
        ]), $this->manager());

        $statement = app(LedgerReports::class)->partyStatement($client, '2026-11-06', '2026-11-30');
        $this->assertSame('1200.0000', $statement['opening']);        // the invoice of 5 November
        $this->assertSame(['700.0000', '500.0000'], array_column($statement['rows'], 'running'));
        $this->assertSame('500.0000', $statement['closing']);

        $accountant = $this->accountant();
        $this->actingAs($accountant)->get(route('finance-reports.party-statement', ['kind' => 'client', 'party_id' => $client->id, 'from' => '2026-11-06', 'to' => '2026-11-30']))
            ->assertOk()->assertSee('700.00 Dr')->assertSee('500.00 Dr');
        $this->actingAs($accountant)->get(route('finance-reports.party-statement', ['kind' => 'client', 'party_id' => $client->id, 'format' => 'csv']))
            ->assertOk()->assertDownload();
        $this->actingAs($accountant)->get(route('finance-reports.party-statement', ['kind' => 'supplier', 'party_id' => $client->id]))->assertNotFound();
    }

    public function test_a_sales_user_limited_to_own_clients_sees_only_their_statements(): void
    {
        Role::findByName(Roles::SALES)->givePermissionTo('finance_reports.view');
        $sales = $this->userWith(Roles::SALES);
        $mine = Party::factory()->client()->create(['account_manager_id' => $sales->id]);
        $theirs = Party::factory()->client()->create();
        $this->assertTrue(BusinessSetting::current()->sales_own_clients_only);

        $this->actingAs($sales)->get(route('finance-reports.party-statement', ['kind' => 'client', 'party_id' => $mine->id]))->assertOk();
        $this->actingAs($sales)->get(route('finance-reports.party-statement', ['kind' => 'client', 'party_id' => $theirs->id]))->assertForbidden();
        $this->actingAs($sales)->get(route('finance-reports.party-statement', ['kind' => 'client']))
            ->assertOk()->assertSee($mine->name)->assertDontSee($theirs->name);
        // Sales does not see suppliers unless the option says so.
        $supplier = Party::factory()->supplier()->create();
        $this->actingAs($sales)->get(route('finance-reports.party-statement', ['kind' => 'supplier', 'party_id' => $supplier->id]))->assertForbidden();
    }

    public function test_the_chart_tree_renders_with_balances_and_a_heading_cannot_be_posted_to(): void
    {
        $manager = $this->manager();
        $this->posting()->post($this->draft($manager, '6101', '1101', '250'), $manager);

        $rows = collect(app(LedgerReports::class)->chart())->keyBy(fn ($r) => $r['account']->code);
        $this->assertSame(0, $rows['1']['depth']);
        $this->assertSame(1, $rows['11']['depth']);
        $this->assertSame(2, $rows['1101']['depth']);
        $this->assertSame('-250.0000', $rows['1101']['amount']);
        $this->assertSame('-250.0000', $rows['1']['amount']);     // the heading sums its children
        $this->assertSame('250.0000', $rows['6']['amount']);

        $this->actingAs($manager)->get(route('accounting.index'))->assertOk()
            ->assertSee('Cash and bank — Hong Kong')->assertSee('-250.00');

        $this->actingAs($manager)->post(route('accounting.journal.store'), ['entry_date' => '2026-11-10', 'lines' => [
            ['account_id' => $this->account('11')->id, 'debit' => '5'],
            ['account_id' => $this->account('3101')->id, 'credit' => '5'],
        ]])->assertSessionHas('error', 'Account 11 is a heading; post to one of its sub-accounts.');
    }

    public function test_the_chart_keeps_the_ledger_true(): void
    {
        $manager = $this->manager();
        $service = app(ChartOfAccountsService::class);
        $this->actingAs($manager);

        $new = $service->create(['code' => '1206', 'name' => 'Payment agent — RMB', 'type' => 'asset', 'parent_id' => $this->account('12')->id, 'is_postable' => true, 'currency' => 'CNY', 'active' => true], $manager);
        $this->assertDatabaseHas('audit_logs', ['model_type' => Account::class, 'model_id' => $new->id, 'action' => 'created']);

        $refusals = [
            fn () => $service->create(['code' => '1207', 'name' => 'X', 'type' => 'expense', 'parent_id' => $this->account('12')->id, 'is_postable' => true], $manager),
            fn () => $service->create(['code' => '1208', 'name' => 'X', 'type' => 'asset', 'parent_id' => $this->account('1101')->id, 'is_postable' => true], $manager),
            fn () => $service->update($this->account('1'), ['name' => 'Assets', 'type' => 'asset', 'parent_id' => $this->account('11')->id, 'is_postable' => false], $manager),
            fn () => $service->update($this->account('11'), ['name' => 'HK', 'type' => 'asset', 'parent_id' => $this->account('1')->id, 'is_postable' => true], $manager),
        ];
        foreach ($refusals as $i => $refused) {
            try {
                $refused();
                $this->fail("chart change {$i} was accepted");
            } catch (AccountingException) {
                $this->assertTrue(true);
            }
        }

        $this->posting()->post($this->draft($manager, '6101', '1101', '10'), $manager);
        $account = $this->account('6101');
        foreach ([['is_postable' => false], ['type' => 'asset'], ['currency' => 'CNY']] as $change) {
            try {
                $service->update($account, $change + ['name' => $account->name, 'type' => $account->type, 'parent_id' => $account->parent_id, 'is_postable' => true, 'active' => true], $manager);
                $this->fail('changed an account with postings: '.json_encode($change));
            } catch (AccountingException $e) {
                $this->assertStringContainsString('6101', $e->getMessage());
            }
        }

        // Renaming, translating and switching off are fine; the code never changes.
        $this->actingAs($manager)->put(route('accounting.accounts.update', $account), [
            'code' => '9999', 'name' => 'Office rent', 'name_ar' => 'إيجار المكتب', 'type' => 'expense',
            'parent_id' => $account->parent_id, 'is_postable' => '1', 'active' => '0',
        ])->assertSessionHas('success');
        $account->refresh();
        $this->assertSame(['6101', 'Office rent', false], [$account->code, $account->name, $account->active]);
    }

    public function test_a_posting_rule_points_at_postable_accounts_on_the_sides_it_sets(): void
    {
        $accountant = $this->accountant();
        $rule = PostingRule::query()->where('event', 'client_invoice')->firstOrFail();

        $this->actingAs($accountant)->put(route('accounting.posting-rules.update', $rule), [
            'debit_account_id' => $this->account('1401')->id, 'credit_account_id' => $this->account('4201')->id,
        ])->assertSessionHas('success');
        $this->assertSame($this->account('4201')->id, $rule->fresh()->credit_account_id);

        $this->actingAs($accountant)->put(route('accounting.posting-rules.update', $rule), [
            'debit_account_id' => $this->account('14')->id, 'credit_account_id' => $this->account('4201')->id,
        ])->assertSessionHas('error');
        $this->assertSame($this->account('1401')->id, $rule->fresh()->debit_account_id);

        // A payment rule's money side stays with the money account.
        $receipt = PostingRule::query()->where('event', 'client_receipt')->firstOrFail();
        $this->actingAs($accountant)->put(route('accounting.posting-rules.update', $receipt), [
            'debit_account_id' => $this->account('1101')->id, 'credit_account_id' => $this->account('2101')->id,
        ])->assertSessionHas('success');
        $this->assertSame([null, $this->account('2101')->id], [$receipt->fresh()->debit_account_id, $receipt->fresh()->credit_account_id]);
        $this->actingAs($accountant)->get(route('accounting.posting-rules.index'))->assertOk()->assertSee('client_receipt');
    }

    public function test_the_account_ledger_and_cash_position(): void
    {
        $client = Party::factory()->client()->create();
        $this->aFewPostings($client, Party::factory()->supplier()->create());
        $reports = app(LedgerReports::class);

        $ledger = $reports->accountLedger($this->account('1401'), '2026-11-01', '2026-11-30', $this->manager());
        $this->assertSame('0.0000', $ledger['opening']);
        $this->assertSame(['1200.0000', '700.0000'], array_column($ledger['rows'], 'running'));
        $this->assertSame($client->name, $ledger['rows'][0]['party_name']);

        $cash = $reports->cashPosition('2026-11-30');
        $this->assertSame(['CNY' => '-2000.0000', 'USD' => '200.0000'], $cash['by_currency']);
        // Both USD money accounts share 1101: counted once.
        $this->assertSame('9920.0000', $cash['book_total']);

        $this->actingAs($this->accountant())->get(route('finance-reports.account-ledger', ['account_id' => $this->account('1401')->id, 'from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()->assertSee('1,200.00');
    }
}
