<?php

namespace Tests\Feature\Integration;

use App\Models\GoodsReceipt;
use App\Models\OrderStepDefinition;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Models\SupplierOrderStepEvent;
use App\Models\User;
use App\Security\Roles;
use App\Services\Shipping\ContainerTrackingService;
use App\Services\Shipping\ShipmentService;
use App\Services\SupplierOrders\OrderStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Steps other modules complete also reopen when what completed them is undone, so the
 * follow-up board never shows an order booked or loaded that no longer is.
 */
class StepAutomationTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create()->assignRole(Roles::MANAGER);
    }

    private function state(SupplierOrder $order, string $code): ?string
    {
        return app(OrderStepService::class)->latestEvents($order->fresh())->get(OrderStepDefinition::where('code', $code)->value('id'))?->status;
    }

    /** An open order with one line of 10 cartons, all received. */
    private function receivedOrder(): SupplierOrderLine
    {
        $pi = ProformaInvoice::factory()->create();
        $piLine = PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'line_no' => 1, 'cartons' => 10]);
        $line = SupplierOrderLine::factory()->create(['pi_line_id' => $piLine->id, 'cartons' => 10]);
        GoodsReceipt::create(['supplier_order_line_id' => $line->id, 'received_on' => now()->toDateString(), 'cartons' => 10, 'inspection_result' => 'pass', 'user_id' => $this->manager->id]);

        return $line;
    }

    public function test_unbooking_an_order_from_its_only_shipment_reopens_booking(): void
    {
        $order = $this->receivedOrder()->supplierOrder;
        $shipping = app(ShipmentService::class);
        $first = $shipping->create(['mode' => 'sea_fcl'], $this->manager);
        $second = $shipping->create(['mode' => 'sea_fcl'], $this->manager);

        $shipping->bookOrder($first, $order, $this->manager);
        $shipping->bookOrder($second, $order, $this->manager);
        $this->assertSame('done', $this->state($order, 'booking'));

        $shipping->unbookOrder($first, $order, $this->manager);
        $this->assertSame('done', $this->state($order, 'booking'), 'still booked on the second shipment');

        $shipping->unbookOrder($second, $order, $this->manager);
        $this->assertSame('reopened', $this->state($order, 'booking'));
    }

    public function test_unloading_reopens_loading_and_reloading_completes_it_again(): void
    {
        $line = $this->receivedOrder();
        $order = $line->supplierOrder;
        $shipping = app(ShipmentService::class);
        $shipment = $shipping->create(['mode' => 'sea_fcl'], $this->manager);
        $container = $shipping->addContainer($shipment, ['container_no' => 'MSKU0000001', 'container_type' => '40HQ'], $this->manager);
        $shipping->bookOrder($shipment, $order, $this->manager);
        $tracking = app(ContainerTrackingService::class);

        $tracking->load($container, $line, '10', now()->toDateString(), $this->manager);
        $this->assertSame('done', $this->state($order, 'loading'));

        $tracking->unload($container, $line, '4', now()->toDateString(), $this->manager);
        $this->assertSame('reopened', $this->state($order, 'loading'));

        $tracking->load($container, $line, '4', now()->toDateString(), $this->manager);
        $this->assertSame('done', $this->state($order, 'loading'));
    }

    public function test_a_step_a_person_marked_not_applicable_stays_so(): void
    {
        $order = $this->receivedOrder()->supplierOrder;
        $step = OrderStepDefinition::where('code', 'booking')->first();
        app(OrderStepService::class)->skip($order, $step, $this->manager, 'Client collects at the factory');

        $this->assertNull(app(OrderStepService::class)->reopenAutomatic($order, 'booking', $this->manager));
        $this->assertSame('skipped', $this->state($order, 'booking'));
    }

    public function test_the_notes_the_system_writes_read_in_the_users_language(): void
    {
        $note = fn (?string $text) => (new SupplierOrderStepEvent(['note' => $text]))->displayNote();

        app()->setLocale('ar');
        $this->assertSame('حُجز على SH-2026-0001', $note('Booked on SH-2026-0001'));
        $this->assertSame('الدفعة PAY-2026-0002', $note('Payment PAY-2026-0002'));
        $this->assertSame('استُلمت كل الكراتين', $note('All cartons received'));
        $this->assertSame('Called the factory: Booked on Monday', $note('Called the factory: Booked on Monday'), 'a person\'s note shows as typed');
        $this->assertNull($note(null));

        app()->setLocale('en');
        $this->assertSame('Loaded in MSKU7712345', $note('Loaded in MSKU7712345'));
    }
}
