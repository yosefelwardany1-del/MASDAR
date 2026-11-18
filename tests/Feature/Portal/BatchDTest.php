<?php

namespace Tests\Feature\Portal;

use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\Quotation;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Security\Roles;
use App\Services\Accounting\PostingService;
use App\Services\Ai\Drafts\TradeDrafts;
use App\Services\Ai\OpenRouterClient;
use App\Services\Ai\StructuredReply;
use App\Services\Portal\ClientPortalService;
use App\Services\Quotations\QuotationService;
use App\Services\Reports\AgeingQueries;
use App\Services\Reports\ProfitQueries;
use App\Services\Samples\SampleService;
use App\Services\ServiceInvoices\ServiceInvoiceService;
use App\Services\Shipping\WarehouseStock;
use App\Services\SupplierOrders\GoodsReceiptService;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.8, batch D: samples, service invoices, the warehouse, tracking links, AI drafts, the client portal. */
class BatchDTest extends TestCase
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

    public function test_a_sample_goes_from_request_to_the_clients_answer(): void
    {
        $sales = User::where('email', 'sales@example.test')->sole();
        $service = app(SampleService::class);
        $sample = $service->create(['description' => 'Bamboo board with laser logo', 'client_id' => $this->pi->client_id, 'quantity' => '2'], $sales);
        $this->assertSame(['SMP-'.now()->year.'-0001', 'requested'], [$sample->number, $sample->fresh()->status()]);
        $this->assertThrows(fn () => $service->record($sample, ['status' => 'sent'], $sales), ValidationException::class);
        $service->record($sample, ['status' => 'sent', 'courier' => 'DHL', 'tracking_no' => '1234567890'], $sales);
        $service->record($sample, ['status' => 'approved', 'note' => 'Client approved the colour'], $sales);
        $this->assertSame('approved', $sample->fresh()->status());
        $this->assertSame('1234567890', $sample->fresh()->shipment()->tracking_no);
        $this->actingAs($sales)->get(route('samples.show', $sample))->assertOk()->assertSee('DHL');
        $other = User::factory()->create()->assignRole(Roles::SALES);
        $this->actingAs($other)->get(route('samples.show', $sample))->assertNotFound();
    }

    public function test_a_service_invoice_is_issued_posted_counted_in_profit_and_ageing(): void
    {
        $service = app(ServiceInvoiceService::class);
        $invoice = $service->createDraft(['proforma_invoice_id' => $this->pi->id, 'lines' => [
            ['category' => 'inspection', 'description' => 'Pre-shipment inspection, 1 man-day', 'unit_price' => '180'],
            ['category' => 'commission', 'description' => 'Sourcing commission', 'unit_price' => '320'],
        ]], $this->manager);
        $this->assertSame(['USD', $this->pi->client_id], [$invoice->currency, $invoice->client_id]);
        $invoice = $service->issue($invoice, $this->manager);
        $this->assertSame('INV-'.now()->year.'-0001', $invoice->number);
        DB::beginTransaction();
        try {
            DB::table('service_invoice_lines')->where('service_invoice_id', $invoice->id)->update(['unit_price' => 1]);
            $this->fail('an issued invoice keeps its lines');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }

        $entry = app(PostingService::class)->postServiceInvoice($invoice, $this->manager);
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($l) => $l->account->code);
        $this->assertSame(['500.0000', '180.0000', '320.0000'], [(string) $lines['1401']->debit, (string) $lines['4301']->credit, (string) $lines['4201']->credit]);

        $this->assertSame('500.0000', app(ProfitQueries::class)->forPi($this->pi)['fees']);
        $row = collect(app(AgeingQueries::class)->report('receivable', now()->toDateString(), $this->manager)['rows'])->firstWhere('party.id', $this->pi->client_id);
        $this->assertSame('8009.6000', $row['open'], '7,509.60 on the PI and 500 of services');

        $this->actingAs($this->manager)->get(route('service-invoices.print', $invoice))->assertOk()->assertSee('INVOICE')->assertSee('Pre-shipment inspection');
        $this->assertThrows(fn () => $service->void($invoice->fresh(), 'Wrong client', $this->manager), ValidationException::class);
    }

    public function test_the_warehouse_shows_what_is_received_and_not_loaded(): void
    {
        $this->assertCount(0, app(WarehouseStock::class)->lines($this->manager), 'the first factory\'s goods are all loaded');
        $order = SupplierOrder::query()->whereHas('supplier', fn ($q) => $q->where('name', 'Jinhua Steelware Co., Ltd.'))->sole();
        app(GoodsReceiptService::class)->receive($order->lines->first(), ['received_on' => now()->subDays(20)->toDateString(), 'cartons' => '60', 'inspection_result' => 'pass', 'location' => 'Bay 3'], $this->manager);
        $lines = app(WarehouseStock::class)->lines($this->manager);
        $this->assertCount(1, $lines);
        $this->assertSame(['60.0000', 'Bay 3', 20], [$lines->first()->in_stock, $lines->first()->location, $lines->first()->days]);
        $this->actingAs($this->manager)->get(route('goods-receipts.stock'))->assertOk()->assertSee('Bay 3');
    }

    public function test_a_container_links_to_the_shipping_lines_tracking_page(): void
    {
        $forwarder = Party::where('name', 'Ningbo Fast Forwarding')->sole();
        $forwarder->forceFill(['tracking_url_template' => 'https://track.example/{container}?bl={bl}'])->save();
        $this->actingAs($this->manager)->get(route('containers.show', Container::sole()))->assertOk()
            ->assertSee('https://track.example/MSKU7712345', false);
    }

    public function test_ai_drafts_are_proposed_and_saved_only_when_a_person_adds_them(): void
    {
        config(['ai.enabled' => true, 'ai.api_key' => 'test-key']);
        $this->mock(OpenRouterClient::class, function ($mock) {
            $mock->shouldReceive('structured')->andReturn(new StructuredReply(data: ['lines' => [
                ['description' => 'Glass jar 500ml with bamboo lid', 'quantity' => 3000, 'unit' => 'pcs', 'target_price' => 0.8, 'note' => null],
                ['description' => 'Spice rack', 'quantity' => null, 'unit' => 'boxes?', 'target_price' => null, 'note' => 'colour to confirm'],
            ]], model: 'test'));
        });
        $lines = app(TradeDrafts::class)->quotationLines('We need 3000 glass jars...', ['pcs' => 'Pieces']);
        $this->assertSame([['Glass jar 500ml with bamboo lid', '3000', 'pcs', '0.8'], ['Spice rack', null, null, null]],
            array_map(fn ($l) => [$l['description'], $l['quantity'], $l['unit'], $l['target_price']], $lines));

        $quotation = app(QuotationService::class)->create(['client_id' => $this->pi->client_id, 'currency' => 'USD'], $this->manager);
        $this->actingAs($this->manager)->post(route('quotations.ai.read', $quotation), ['message' => 'We need 3000 glass jars'])->assertSessionHas('ai_lines');
        $this->assertSame(0, $quotation->lines()->count(), 'nothing is saved by the AI');
        $this->actingAs($this->manager)->post(route('quotations.ai.add', $quotation), ['lines' => [
            ['take' => '1', 'description' => 'Glass jar 500ml with bamboo lid', 'unit' => 'pcs', 'quantity' => '3000', 'unit_price' => '0.92'],
            ['description' => 'Spice rack', 'unit' => 'pcs'],
        ]])->assertRedirect();
        $this->assertSame(1, $quotation->lines()->count());
        $this->assertSame('0.9200', (string) $quotation->lines()->sole()->unit_price);
        $this->assertTrue(Quotation::whereKey($quotation->id)->exists());
    }

    public function test_the_client_portal_shows_a_client_only_their_own(): void
    {
        $client = $this->pi->client;
        [$account, $password] = app(ClientPortalService::class)->grant($client, 'buyer@gulfhome.example', 'Omar', $this->manager);
        $this->assertTrue($account->isPortalUser());
        $this->assertSame([], $account->getRoleNames()->all());

        // Off until switched on.
        $this->actingAs($account)->get(route('portal.home'))->assertForbidden();
        BusinessSetting::current()->fill(['client_portal_enabled' => true])->save();
        $this->actingAs($account)->get(route('portal.home'))->assertOk()->assertSee($this->pi->reference())->assertSee('MSKU7712345')
            ->assertDontSee('Nile Kitchenware')->assertDontSee('Yiwu Bright Housewares Factory');
        $this->actingAs($account)->get(route('portal.pi', $this->pi))->assertOk()->assertSee('Proforma Invoice (PI)');
        $this->actingAs($account)->get(route('portal.container', Container::sole()))->assertOk()->assertDontSee('Yiwu Bright');
        $this->actingAs($account)->get(route('portal.statement'))->assertOk();

        // Another client's PI, and every staff screen, are out of reach.
        $other = ProformaInvoice::query()->whereHas('client', fn ($q) => $q->where('name', 'Nile Kitchenware Co.'))->first();
        $this->actingAs($account)->get(route('portal.pi', $other))->assertNotFound();
        $this->actingAs($account)->get(route('dashboard'))->assertRedirect(route('portal.home'));
        $this->actingAs($account)->get(route('proforma-invoices.show', $this->pi))->assertRedirect(route('portal.home'));
        $this->actingAs($this->manager)->get(route('portal.home'))->assertNotFound();

        // Signing in lands on the portal.
        auth()->logout();
        $this->post(route('login.post'), ['email' => 'buyer@gulfhome.example', 'password' => $password])->assertRedirect(route('portal.home'));

        app(ClientPortalService::class)->revoke($account, $this->manager);
        $this->assertSame('inactive', $account->fresh()->status);
    }
}
