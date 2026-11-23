<?php

namespace Tests\Feature\Accounting;

use App\Models\Container;
use App\Models\MoneyAccount;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Every accounting page renders with data in it, and every report downloads as CSV. */
class ScreensRenderTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    public function test_journal_pages_render_with_their_lines(): void
    {
        $manager = $this->manager();
        $client = Party::factory()->client()->create();
        $draft = $this->posting()->createDraft(['entry_date' => '2026-11-10', 'description' => 'Adjustment', 'lines' => [
            ['account_id' => $this->account('1401')->id, 'party_id' => $client->id, 'currency' => 'CNY', 'debit' => '700', 'rate' => '0.14', 'memo' => 'Opening'],
            ['account_id' => $this->account('3201')->id, 'credit' => '98'],
        ]], $manager);

        $this->actingAs($manager)->get(route('accounting.journal.show', $draft))->assertOk()->assertSee($client->name)->assertSee('98.00');
        $this->actingAs($manager)->get(route('accounting.journal.edit', $draft))->assertOk()->assertSee('Opening');
        $this->actingAs($manager)->get(route('accounting.journal.index', ['status' => 'draft', 'search' => 'Adjust']))->assertOk()->assertSee('Adjustment');

        $posted = $this->posting()->post($draft, $manager);
        $this->actingAs($manager)->get(route('accounting.journal.show', $posted))->assertOk()->assertSee($posted->number);
        $this->actingAs($manager)->get(route('accounting.journal.index', ['account_id' => $this->account('1401')->id, 'event' => 'manual']))->assertOk()->assertSee($posted->number);
        $this->actingAs($manager)->get(route('accounting.accounts.edit', $this->account('1401')))->assertOk();
        $this->actingAs($manager)->get(route('accounting.journal.create'))->assertOk()
            ->assertSee('id="line-template"', false)->assertSee('lines[__INDEX__][account_id]', false);
    }

    public function test_the_posting_queue_renders_every_section(): void
    {
        $accountant = $this->accountant();
        $this->fxRate('CNY', '2026-11-01', '0.140000');
        $pi = $this->confirmedPi();
        $order = $this->supplierOrder();
        $payment = $this->payment(['kind' => 'client_receipt', 'money_account_id' => MoneyAccount::factory()->rmb()->create()->id]);
        $company = $this->shippingCompany();
        Container::factory()->create(['container_no' => 'MSKU1234565']);

        $this->actingAs($accountant)->get(route('accounting.to-post.index'))->assertOk()
            ->assertSee($pi->pi_number)->assertSee($order->number)->assertSee($payment->number)
            ->assertSee($company->name)->assertSee('MSKU1234565');
    }

    public function test_every_report_downloads_as_csv(): void
    {
        $manager = $this->manager();
        $this->posting()->post($this->draft($manager), $manager);
        $client = Party::factory()->client()->create();
        MoneyAccount::factory()->create();

        foreach ([
            ['finance-reports.trial-balance', []],
            ['finance-reports.profit-and-loss', []],
            ['finance-reports.balance-sheet', []],
            ['finance-reports.account-ledger', ['account_id' => $this->account('1101')->id]],
            ['finance-reports.party-statement', ['kind' => 'client', 'party_id' => $client->id]],
            ['finance-reports.cash-position', []],
        ] as [$route, $query]) {
            $this->actingAs($manager)->get(route($route, $query))->assertOk();
            $response = $this->actingAs($manager)->get(route($route, $query + ['format' => 'csv']));
            $response->assertOk()->assertDownload();
            $this->assertStringContainsString(',', $response->streamedContent(), $route);
        }
    }
}
