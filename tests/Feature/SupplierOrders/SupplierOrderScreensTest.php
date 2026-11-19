<?php

namespace Tests\Feature\SupplierOrders;

use App\Models\OrderStepDefinition;
use App\Models\Party;
use App\Models\PiLine;
use App\Models\Product;
use App\Models\SupplierOrderLine;
use App\Security\Roles;
use App\Services\SupplierOrders\OrderStepService;
use App\Services\SupplierOrders\SupplierOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** The follow-up board, the order page, the goods receipt screen and the evaluation: who gets in and what they see. */
class SupplierOrderScreensTest extends TestCase
{
    use BuildsSupplierOrders, RefreshDatabase, SeedsReference;

    public function test_staff_is_refused_every_screen(): void
    {
        $staff = $this->user(Roles::STAFF);
        $order = $this->orderWithLines();

        $this->actingAs($staff)->get(route('supplier-orders.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('supplier-orders.show', $order))->assertForbidden();
        $this->actingAs($staff)->get(route('supplier-orders.evaluation'))->assertForbidden();
        $this->actingAs($staff)->get(route('goods-receipts.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('goods-receipts.store'), [])->assertForbidden();
    }

    public function test_warehouse_qc_receives_goods_but_does_not_edit_orders(): void
    {
        $qc = $this->user(Roles::WAREHOUSE_QC);
        $order = $this->orderWithLines();
        $line = $order->lines()->first();

        $this->actingAs($qc)->get(route('goods-receipts.index'))->assertOk()->assertSee($order->number);
        $this->actingAs($qc)->post(route('goods-receipts.store'), [
            'supplier_order_line_id' => $line->id, 'received_on' => now()->toDateString(), 'cartons' => 4, 'inspection_result' => 'pass',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('4.0000', (string) $line->receipts()->value('cartons'));

        $this->actingAs($qc)->get(route('supplier-orders.show', $order))->assertOk();
        $this->actingAs($qc)->put(route('supplier-orders.update', $order), ['currency' => 'USD'])->assertForbidden();
        $this->actingAs($qc)->post(route('supplier-orders.steps.done', [$order, $this->step('artwork')]), ['happened_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($qc)->post(route('supplier-orders.delivery-date', $order), ['delivery_date' => now()->toDateString(), 'reason' => 'x'])->assertForbidden();
        $this->actingAs($qc)->get(route('supplier-orders.create', ['pi' => 1]))->assertForbidden();
    }

    public function test_the_board_shows_a_column_and_a_pill_per_step(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $late = $this->orderWithLines(1, 10, ['delivery_date' => Carbon::today()->addDays(5), 'merchandiser_id' => $merch->id]);
        $calm = $this->orderWithLines(1, 10, ['delivery_date' => Carbon::today()->addDays(90)]);
        $done = $this->orderWithLines(1, 10, ['status' => 'completed']);
        app(OrderStepService::class)->markDone($late, $this->step('artwork'), $merch, Carbon::today()->subDay()->toDateString());

        $response = $this->actingAs($merch)->get(route('supplier-orders.index'))->assertOk();

        foreach (OrderStepDefinition::where('active', true)->get() as $step) {
            $response->assertSee($step->displayName());
        }
        $response->assertSee($late->number)->assertSee($calm->number)->assertDontSee($done->number)
            ->assertSee($late->supplier->name)
            ->assertSee('table-responsive app-board', false)
            // The artwork done yesterday, the initial sample late since its due date (21 days before delivery).
            ->assertSee(Carbon::today()->subDay()->format('Y-m-d'))
            ->assertSee(Carbon::today()->addDays(5)->subDays(21)->format('Y-m-d'));
        $html = $response->getContent();
        // Beyond the one in the key: artwork done; initial and final sample late; labelling due in 2 days.
        $this->assertGreaterThan(1, substr_count($html, 'app-pill app-pill--done'));
        $this->assertGreaterThan(2, substr_count($html, 'app-pill app-pill--late'));
        $this->assertGreaterThan(1, substr_count($html, 'app-pill app-pill--due'));
        // Late rows first.
        $this->assertLessThan(strpos($response->getContent(), $calm->number), strpos($response->getContent(), $late->number));

        $this->actingAs($merch)->get(route('supplier-orders.index', ['late' => 1]))->assertOk()
            ->assertSee($late->number)->assertDontSee($calm->number);
        $this->actingAs($merch)->get(route('supplier-orders.index', ['status' => 'completed']))->assertOk()
            ->assertSee($done->number)->assertDontSee($late->number);
        $this->actingAs($merch)->get(route('supplier-orders.index', ['search' => $calm->number]))->assertOk()
            ->assertSee($calm->number)->assertDontSee($late->number);
        $this->actingAs($merch)->get(route('supplier-orders.index', ['supplier' => $calm->supplier_id]))->assertOk()
            ->assertSee($calm->number)->assertDontSee($late->number);
        $this->actingAs($merch)->get(route('supplier-orders.index', ['merchandiser' => $merch->id]))->assertOk()
            ->assertSee($late->number)->assertDontSee($calm->number);
        $client = $calm->lines()->first()->piLine->proformaInvoice->client;
        $this->actingAs($merch)->get(route('supplier-orders.index', ['client' => $client->id]))->assertOk()
            ->assertSee($calm->number)->assertSee($client->name)->assertDontSee($late->number);
    }

    public function test_the_board_marks_a_moved_delivery_date(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $order = $this->orderWithLines(1, 10, ['delivery_date' => Carbon::today()->addDays(20)]);
        app(SupplierOrderService::class)->changeDeliveryDate($order, Carbon::today()->addDays(30)->toDateString(), 'Factory holiday', $merch);

        $this->actingAs($merch)->get(route('supplier-orders.index'))->assertOk()
            ->assertSee('bi-calendar-x', false)
            ->assertSee(Carbon::today()->addDays(20)->format('Y-m-d'));
    }

    public function test_supplier_names_and_prices_are_hidden_from_a_sales_user(): void
    {
        $sales = $this->user(Roles::SALES);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id]);
        $pi = $this->orderablePi([['supplier_price' => '13.5791']], ['client_id' => $client->id]);
        $supplier = $this->supplier(['name' => 'Hidden Factory Ltd']);
        $order = $this->orderWithLines(0, 10, ['supplier_id' => $supplier->id]);
        SupplierOrderLine::factory()->create(['supplier_order_id' => $order->id, 'pi_line_id' => $pi->lines()->first()->id, 'unit_price' => '13.5791']);
        $other = $this->orderWithLines(); // another account manager's client

        $this->actingAs($sales)->get(route('supplier-orders.index'))->assertOk()
            ->assertSee($order->number)->assertSee($client->name)
            ->assertDontSee('Hidden Factory Ltd')
            ->assertDontSee($other->number);
        $this->actingAs($sales)->get(route('supplier-orders.show', $order))->assertOk()
            ->assertDontSee('Hidden Factory Ltd')->assertDontSee('13.5791')->assertDontSee(__('Attachments'));
        $this->actingAs($sales)->get(route('supplier-orders.show', $other))->assertNotFound();
        $this->actingAs($sales)->get(route('supplier-orders.evaluation'))->assertOk()->assertDontSee('Hidden Factory Ltd');

        $merch = $this->user(Roles::MERCHANDISER);
        $this->actingAs($merch)->get(route('supplier-orders.show', $order))->assertOk()
            ->assertSee('Hidden Factory Ltd')->assertSee('13.5791')->assertSee(__('Attachments'));
    }

    public function test_the_order_page_shows_lines_steps_and_history(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $order = $this->orderWithLines(2, 10, ['delivery_date' => Carbon::today()->addDays(5)]);
        $product = Product::factory()->create(['photo' => 'products/mug.jpg', 'photo_disk' => 'local']);
        SupplierOrderLine::factory()->create(['supplier_order_id' => $order->id, 'pi_line_id' => PiLine::factory()->create(['product_id' => $product->id])->id]);
        app(OrderStepService::class)->skip($order, $this->step('artwork'), $merch, 'Stock goods, no artwork');

        $this->actingAs($merch)->get(route('supplier-orders.show', $order))->assertOk()
            ->assertSee($order->number)
            ->assertSee('src="'.route('products.photo', $product).'"', false)
            ->assertSee('class="app-thumb"', false)
            ->assertSee('onerror="this.style.display=\'none\'"', false)
            ->assertSee('Stock goods, no artwork')
            ->assertSee($this->step('contract_stamped')->displayName())
            ->assertSee(route('supplier-orders.steps.done', [$order, $this->step('initial_sample')]), false)
            ->assertSee(route('supplier-orders.delivery-date', $order), false);
    }
}
