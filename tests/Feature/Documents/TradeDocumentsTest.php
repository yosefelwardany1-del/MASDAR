<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\Party;
use App\Models\Quotation;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Security\Roles;
use App\Services\Quotations\QuotationService;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** The documents around the PI: quotations, the purchase contract, the commercial invoice. */
class TradeDocumentsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

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

    public function test_a_quotation_is_sent_never_changes_and_becomes_a_draft_pi(): void
    {
        $sales = User::factory()->create()->assignRole(Roles::SALES);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id]);
        $service = app(QuotationService::class);

        $quotation = $service->create(['client_id' => $client->id, 'currency' => 'USD'], $sales);
        $this->assertStringStartsWith('DRAFT-', $quotation->number);
        $this->assertSame(now()->addDays(30)->toDateString(), $quotation->valid_until->toDateString(), 'valid for the default days');
        $this->assertThrows(fn () => $service->send($quotation, $sales), ValidationException::class);

        $service->addLine($quotation, ['description' => "Glass jar 500ml\nWith bamboo lid", 'unit' => 'pcs', 'qty_per_carton' => '24',
            'unit_price' => '0.92', 'moq' => '2400', 'cartons' => '100'], $sales);
        $quotation = $service->send($quotation, $sales);
        $this->assertSame('QT-'.now()->year.'-0001', $quotation->number);

        // Sent: neither it nor its lines change.
        $this->assertRefused(fn () => DB::table('quotations')->where('id', $quotation->id)->update(['valid_until' => now()->addYear()]));
        $this->assertRefused(fn () => DB::table('quotation_lines')->where('quotation_id', $quotation->id)->update(['unit_price' => 1]));
        $this->assertThrows(fn () => $service->addLine($quotation, ['description' => 'x', 'unit' => 'pcs', 'unit_price' => '1'], $sales), ValidationException::class);

        // Becoming a PI needs the PI screen too: sales has it.
        $pi = $service->convert($quotation, $sales);
        $this->assertSame('draft', $pi->status);
        $line = $pi->lines()->sole();
        $this->assertSame(['100.0000', '24.0000', '0.9200'], [$line->cartons, $line->qty_per_carton, $line->unit_price]);
        $this->assertSame(['accepted', $pi->id], [$quotation->fresh()->status, $quotation->fresh()->proforma_invoice_id]);
        $this->assertThrows(fn () => $service->convert($quotation->fresh(), $sales), ValidationException::class);

        // Another salesperson sees neither the quotation nor its print.
        $other = User::factory()->create()->assignRole(Roles::SALES);
        $this->actingAs($other)->get(route('quotations.show', $quotation))->assertNotFound();
        $this->actingAs($sales)->get(route('quotations.print', $quotation))->assertOk()->assertSee('QUOTATION')->assertSee($quotation->number);
        $this->actingAs($sales)->get(route('quotations.index'))->assertOk()->assertSee($quotation->number);
    }

    public function test_the_purchase_contract_shows_the_factory_its_own_prices_and_never_the_client(): void
    {
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $order = SupplierOrder::query()->whereHas('supplier', fn ($q) => $q->where('name', 'Yiwu Bright Housewares Factory'))->firstOrFail();
        $manager = User::where('email', 'manager@example.test')->sole();

        $this->actingAs($manager)->get(route('supplier-orders.contract', $order))->assertOk()
            ->assertSee('PURCHASE CONTRACT')->assertSee('采购合同')->assertSee('义乌明亮家居用品厂')->assertSee('CNY 3.10')
            ->assertDontSee('Gulf Home Trading LLC')->assertDontSee('$0.875');
        // Whoever may not see supplier prices may not print it.
        $sales = User::where('email', 'sales@example.test')->sole();
        $this->actingAs($sales)->get(route('supplier-orders.contract', $order))->assertForbidden();
    }

    public function test_the_commercial_invoice_lists_one_clients_goods_in_the_container_at_its_prices(): void
    {
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $container = Container::sole();
        $client = Party::where('name', 'Gulf Home Trading LLC')->sole();
        $manager = User::where('email', 'manager@example.test')->sole();

        $this->actingAs($manager)->get(route('containers.commercial-invoice', [$container, 'client' => $client->id]))->assertOk()
            ->assertSee('COMMERCIAL INVOICE')->assertSee('MSKU7712342')->assertSee('7013.49')->assertSee('$2,520.00')->assertSee('FOB Ningbo')
            ->assertDontSee('Yiwu Bright Housewares Factory');
        // A client with nothing in the container has no invoice; neither does whoever cannot see prices.
        $other = Party::where('name', 'Nile Kitchenware Co.')->sole();
        $this->actingAs($manager)->get(route('containers.commercial-invoice', [$container, 'client' => $other->id]))->assertNotFound();
        $qc = User::where('email', 'warehouse_qc@example.test')->sole();
        $this->actingAs($qc)->get(route('containers.commercial-invoice', [$container, 'client' => $client->id]))->assertForbidden();
    }

    public function test_purchase_orders_are_numbered_po_on_a_new_installation(): void
    {
        $this->assertSame('PO', BusinessSetting::current()->supplier_order_prefix);
        $manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $this->actingAs($manager)->get(route('supplier-orders.index'))->assertOk()->assertSee(__('Purchase orders'));
        $this->actingAs($manager)->get(route('proforma-invoices.index'))->assertOk()->assertSee(__('Sales orders (PI)'));
    }
}
