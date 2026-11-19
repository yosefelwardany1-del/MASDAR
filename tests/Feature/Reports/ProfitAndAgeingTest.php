<?php

namespace Tests\Feature\Reports;

use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Claims\TradeNoteService;
use App\Services\Reports\AgeingQueries;
use App\Services\Reports\ProfitQueries;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Spec §5.8: profit per sales order and per container, and receivables and payables by age, on
 * the demo order — 10,728.00 USD of sales; the factories' 16,992 + 26,880 CNY at 0.14.
 */
class ProfitAndAgeingTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $this->manager = User::where('email', 'manager@example.test')->sole();
        $this->pi = ProformaInvoice::query()->current()->whereHas('client', fn ($q) => $q->where('name', 'Gulf Home Trading LLC'))->sole();
    }

    public function test_the_profit_of_an_order_is_its_sales_less_factories_freight_and_notes(): void
    {
        $profit = app(ProfitQueries::class);
        $row = $profit->forPi($this->pi);
        $this->assertNull($row['error']);
        $this->assertSame(['10728.0000', '6142.0800', '0.0000', '4585.9200', '42.7'], [$row['sales'], $row['goods_cost'], $row['container_cost'], $row['profit'], $row['margin']]);
        $this->assertFalse($row['estimated'], 'both factories have their order');

        // The forwarder's bill for the container, 700 USD: all of it this order's (it is alone inside).
        $container = Container::sole();
        app(PostingService::class)->postShippingBill(Party::where('name', 'Ningbo Fast Forwarding')->sole(), $container, '700', 'USD', now()->toDateString(), null, $this->manager);
        // A 100 USD credit note to the client; a 500 CNY debit note on the first factory (70 USD).
        $notes = app(TradeNoteService::class);
        $notes->issue($notes->createDraft('credit', ['proforma_invoice_id' => $this->pi->id, 'reason' => 'Allowance', 'lines' => [['description' => 'x', 'unit_price' => '100']]], $this->manager), $this->manager);
        $order = SupplierOrder::query()->whereHas('supplier', fn ($q) => $q->where('name', 'Yiwu Bright Housewares Factory'))->sole();
        $notes->issue($notes->createDraft('debit', ['supplier_order_id' => $order->id, 'reason' => 'Claim', 'lines' => [['description' => 'x', 'unit_price' => '500']]], $this->manager), $this->manager);

        $row = $profit->forPi($this->pi);
        $this->assertSame(['700.0000', '100.0000', '70.0000', '3855.9200'], [$row['container_cost'], $row['credited'], $row['debited'], $row['profit']]);

        [$byContainer] = $profit->forContainers([$container]);
        // Loaded: the first factory's two lines, 4,728.00 USD of sales for 2,378.88 of goods.
        $this->assertSame(['4728.0000', '2378.8800', '700.0000', '1649.1200'], [$byContainer['sales'], $byContainer['goods_cost'], $byContainer['container_cost'], $byContainer['profit']]);
        $this->assertCount(1, $byContainer['clients']);

        $this->actingAs($this->manager)->get(route('profit.index'))->assertOk()->assertSee($this->pi->reference())->assertSee('3,855.92');
        $this->actingAs($this->manager)->get(route('profit.containers'))->assertOk()->assertSee('MSKU7712342')->assertSee('1,649.12');
        $this->actingAs($this->manager)->get(route('profit.pi', $this->pi))->assertOk()->assertSee('3,855.92');
        $this->actingAs($this->manager)->get(route('profit.index', ['format' => 'csv']))->assertOk();
    }

    public function test_a_shared_container_splits_its_costs_by_the_setting(): void
    {
        $container = Container::sole();
        $costs = app(ProfitQueries::class)->containerCosts([$container->id]);
        $this->assertSame(0, bccomp('0', $costs[$container->id], 4), 'no bill and no expense yet');
        BusinessSetting::current()->fill(['container_cost_split' => 'value'])->save();
        $this->assertSame('value', app(ProfitQueries::class)->split());
    }

    public function test_profit_is_only_for_those_who_see_factory_prices(): void
    {
        $accountant = User::where('email', 'accountant@example.test')->sole();
        $this->actingAs($accountant)->get(route('profit.index'))->assertOk();
        $sales = User::where('email', 'sales@example.test')->sole();
        $this->actingAs($sales)->get(route('profit.index'))->assertForbidden();
    }

    public function test_ageing_settles_the_oldest_first_and_buckets_what_is_left(): void
    {
        $ageing = app(AgeingQueries::class);
        $client = Party::where('name', 'Gulf Home Trading LLC')->sole();

        // Invoiced 10,728.00 today; 3,218.40 received: 7,509.60 open, all current.
        $report = $ageing->report('receivable', now()->toDateString(), $this->manager);
        $row = collect($report['rows'])->firstWhere('party.id', $client->id);
        $this->assertSame(['USD', '7509.6000', '7509.6000', '0.0000'], [$row['currency'], $row['open'], $row['buckets'][0], $row['advance']]);

        // Seventy days on, it is in the 61–90 bucket.
        $later = $ageing->report('receivable', now()->addDays(70)->toDateString(), $this->manager);
        $row = collect($later['rows'])->firstWhere('party.id', $client->id);
        $this->assertSame('7509.6000', $row['buckets'][2]);

        // The factory: billed 16,992 CNY, paid 5,000.
        $payable = $ageing->report('payable', now()->toDateString(), $this->manager);
        $factory = collect($payable['rows'])->firstWhere('party.name', 'Yiwu Bright Housewares Factory');
        $this->assertSame(['CNY', '11992.0000'], [$factory['currency'], $factory['open']]);

        // Buckets are a setting.
        BusinessSetting::current()->fill(['ageing_buckets' => '15,45'])->save();
        $this->assertSame([15, 45], $ageing->buckets());

        $this->actingAs($this->manager)->get(route('finance-reports.ageing'))->assertOk()->assertSee('Gulf Home Trading LLC')->assertSee('7,509.60');
        $this->actingAs($this->manager)->get(route('finance-reports.ageing', ['side' => 'payable']))->assertOk()->assertSee('11,992.00');
        // Sales limited to their own clients sees them; no supplier is named to them.
        $sales = User::where('email', 'sales@example.test')->sole();
        $sales->givePermissionTo('finance_reports.view');
        $this->actingAs($sales)->get(route('finance-reports.ageing', ['side' => 'payable']))->assertOk()->assertDontSee('Yiwu Bright Housewares Factory');
    }
}
