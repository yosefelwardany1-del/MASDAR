<?php

namespace Tests\Feature\Sourcing;

use App\Models\Party;
use App\Models\Quotation;
use App\Models\SourcingRequest;
use App\Models\User;
use App\Services\Accounting\Decimal;
use App\Services\Quotations\QuotationService;
use App\Services\Reports\AnalyticsQueries;
use App\Services\Sourcing\SourcingService;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.8: factory quotes compared and chosen onto a quotation; sales and purchase analysis. */
class SourcingAndAnalyticsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $this->manager = User::where('email', 'manager@example.test')->sole();
    }

    public function test_quotes_are_compared_one_is_chosen_and_it_goes_onto_the_quotation(): void
    {
        $merch = User::where('email', 'merchandiser@example.test')->sole();
        $client = Party::where('name', 'Nile Kitchenware Co.')->sole();
        $service = app(SourcingService::class);
        $request = $service->create(['description' => 'Bamboo cutting board 30×20 cm', 'client_id' => $client->id, 'unit' => 'pcs',
            'quantity' => '3000', 'target_price' => '6.50', 'target_currency' => 'CNY'], $merch);
        $this->assertSame('RFQ-'.now()->year.'-0001', $request->number);

        $factories = Party::query()->suppliers()->orderBy('id')->get();
        $cheap = $service->addQuote($request, ['supplier_id' => $factories[0]->id, 'unit_price' => '6.20', 'currency' => 'CNY', 'moq' => '1000', 'lead_time_days' => '25', 'qty_per_carton' => '40'], $merch);
        $service->addQuote($request, ['supplier_id' => $factories[1]->id, 'unit_price' => '7.10', 'currency' => 'CNY', 'moq' => '500', 'lead_time_days' => '15'], $merch);
        $this->assertThrows(fn () => $service->addQuote($request, ['supplier_id' => $client->id, 'unit_price' => '1', 'currency' => 'CNY'], $merch), ValidationException::class);

        $this->actingAs($merch)->get(route('sourcing.show', $request))->assertOk()->assertSee('Yiwu Bright Housewares Factory')->assertSee(__('Lowest'));
        // Factories' prices: not for sales.
        $sales = User::where('email', 'sales@example.test')->sole();
        $this->actingAs($sales)->get(route('sourcing.index'))->assertForbidden();
        $this->assertThrows(fn () => $service->create(['description' => 'x'], $sales), AuthorizationException::class);

        $service->choose($cheap, $merch);
        $request->refresh();
        $this->assertSame(['chosen', $cheap->id], [$request->status, $request->chosen_quote_id]);
        DB::beginTransaction();
        try {
            DB::table('supplier_quotes')->where('id', $cheap->id)->update(['unit_price' => 1]);
            $this->fail('a decided request keeps its quotes');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }

        // Onto a draft quotation of the client: the factory, its price and packing, our selling price.
        $quotation = app(QuotationService::class)->create(['client_id' => $client->id, 'currency' => 'USD'], $this->manager);
        $line = $service->toQuotation($request, $quotation, '1.15', $this->manager);
        $this->assertSame([$cheap->supplier_id, '6.2000', 'CNY', '1.1500', '40.0000', '1000.0000'],
            [$line->supplier_id, $line->supplier_price, $line->supplier_currency, $line->unit_price, $line->qty_per_carton, $line->moq]);
        $this->assertSame($line->id, $request->fresh()->quotation_line_id);
        $this->assertThrows(fn () => $service->toQuotation($request->fresh(), $quotation, '1.20', $this->manager), ValidationException::class);
        $this->assertThrows(fn () => $service->reopen($request->fresh(), $this->manager), ValidationException::class);
        $this->assertSame(1, Quotation::count() - 2, 'the demo has two quotations');
        $this->assertSame(1, SourcingRequest::count());
    }

    public function test_the_analysis_adds_up_sales_purchases_and_quotations(): void
    {
        $analytics = app(AnalyticsQueries::class);
        $from = now()->subYear()->toDateString();
        $to = now()->toDateString();
        $sales = $analytics->sales($from, $to, $this->manager);
        $this->assertCount(1, $sales, 'one confirmed PI in the demo');
        $this->assertSame('10728.0000', $sales->first()->book);
        $months = $analytics->salesByMonth($sales, $from, $to);
        $this->assertCount(13, $months);
        $this->assertSame('10728.0000', collect($months)->firstWhere('month', now()->format('Y-m'))['total']);

        // 16,992 + 26,880 CNY at 0.14.
        $this->assertSame('6142.0800', Decimal::sum($analytics->purchases($from, $to)->pluck('book')));
        $funnel = $analytics->quotationFunnel($from, $to, $this->manager);
        $this->assertSame([2, 1], [$funnel['total'], $funnel['sent']]);

        $this->actingAs($this->manager)->get(route('analytics.index'))->assertOk()->assertSee('Gulf Home Trading LLC')->assertSee('10,728.00')->assertSee(__('Purchases by factory'));
        // Sales sees their own clients' sales, and no factory.
        $sales = User::where('email', 'sales@example.test')->sole();
        $this->actingAs($sales)->get(route('analytics.index'))->assertOk()->assertSee('10,728.00')->assertDontSee('Jinhua Steelware Co., Ltd.');
    }
}
