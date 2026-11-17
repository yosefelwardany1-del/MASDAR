<?php

namespace Tests\Feature\Shipping;

use App\Models\AuditLog;
use App\Models\OrderStepDefinition;
use App\Models\Party;
use App\Models\Shipment;
use App\Models\ShipmentOrder;
use App\Models\SupplierOrderStepEvent;
use App\Security\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Shipments, their containers and the orders booked on them (spec §5.5, step 9 of §5.3). */
class ShipmentTest extends TestCase
{
    use RefreshDatabase, SeedsReference, ShippingFixtures;

    public function test_a_shipment_is_numbered_and_audited(): void
    {
        $logistics = $this->userWithRole(Roles::LOGISTICS);
        $company = Party::factory()->shippingCompany()->create();

        $this->actingAs($logistics)->post(route('shipments.store'), [
            'shipping_company_id' => $company->id, 'mode' => 'sea_fcl', 'booking_no' => 'BK-77', 'port_of_loading' => 'NGB',
            'port_of_discharge' => 'JEA', 'etd' => '2026-12-01', 'eta' => '2026-12-28', 'vessel' => 'MSC ANNA', 'voyage' => '512W', 'bl_number' => 'BL-1',
        ])->assertSessionHasNoErrors();
        $this->post(route('shipments.store'), ['shipping_company_id' => $company->id])->assertSessionHasNoErrors();

        $numbers = Shipment::orderBy('id')->pluck('number')->all();
        $this->assertSame(['SH-'.now()->year.'-0001', 'SH-'.now()->year.'-0002'], $numbers);
        $first = Shipment::where('number', $numbers[0])->first();
        $this->assertSame('MSC ANNA', $first->vessel);
        $this->assertSame($logistics->id, $first->created_by);
        $this->assertTrue(AuditLog::where('model_type', Shipment::class)->where('model_id', $first->id)->where('action', 'created')->exists());
    }

    public function test_a_shipment_needs_a_shipping_company_and_an_eta_after_its_etd(): void
    {
        $this->actingAs($this->userWithRole(Roles::LOGISTICS))
            ->post(route('shipments.store'), ['etd' => '2026-12-10', 'eta' => '2026-12-01'])
            ->assertSessionHasErrors(['shipping_company_id', 'eta']);
        $this->assertSame(0, Shipment::count());
    }

    public function test_etd_and_eta_changes_are_audited_with_old_and_new_values(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        $shipment = $this->shipmentWith(['etd' => '2026-12-01', 'eta' => '2026-12-20']);

        $this->actingAs($user)->put(route('shipments.update', $shipment), [
            'shipping_company_id' => $shipment->shipping_company_id, 'etd' => '2026-12-03', 'eta' => '2026-12-27',
            'mode' => $shipment->mode, 'port_of_loading' => $shipment->port_of_loading, 'port_of_discharge' => $shipment->port_of_discharge,
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-12-27', $shipment->fresh()->eta->toDateString());
        $log = AuditLog::where('model_type', Shipment::class)->where('model_id', $shipment->id)->where('action', 'updated')->firstOrFail();
        $this->assertEquals(['etd' => '2026-12-01', 'eta' => '2026-12-20'], array_intersect_key($log->old_values, ['etd' => 1, 'eta' => 1]));
        $this->assertEquals(['etd' => '2026-12-03', 'eta' => '2026-12-27'], array_intersect_key($log->new_values, ['etd' => 1, 'eta' => 1]));
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_booking_an_order_marks_the_booking_step_done_once(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, $order] = $this->orderFor();
        $first = $this->shipmentWith();
        $second = $this->shipmentWith();

        $this->actingAs($user)->post(route('shipments.orders.store', $first), ['supplier_order_id' => $order->id])->assertSessionHasNoErrors();
        $this->post(route('shipments.orders.store', $second), ['supplier_order_id' => $order->id])->assertSessionHasNoErrors();

        $this->assertSame(2, ShipmentOrder::where('supplier_order_id', $order->id)->count());
        $booking = OrderStepDefinition::where('code', 'booking')->firstOrFail();
        $events = SupplierOrderStepEvent::where('supplier_order_id', $order->id)->where('step_definition_id', $booking->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame('done', $events[0]->status);
        $this->assertSame('Booked on '.$first->number, $events[0]->note);
        $this->assertTrue(AuditLog::where('model_type', Shipment::class)->where('model_id', $first->id)->where('action', 'order_booked')->exists());

        $this->get(route('shipments.show', $first))->assertOk()->assertSee($order->number);
    }

    public function test_an_order_is_booked_once_per_shipment_and_only_while_open(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, $order] = $this->orderFor();
        [, $closed] = $this->orderFor();
        $closed->update(['status' => 'completed']);
        $shipment = $this->shipmentWith();

        $this->actingAs($user)->post(route('shipments.orders.store', $shipment), ['supplier_order_id' => $order->id])->assertSessionHasNoErrors();
        $this->post(route('shipments.orders.store', $shipment), ['supplier_order_id' => $order->id])->assertSessionHasErrors('supplier_order_id');
        $this->post(route('shipments.orders.store', $shipment), ['supplier_order_id' => $closed->id])->assertSessionHasErrors('supplier_order_id');
        $this->assertSame(1, ShipmentOrder::where('shipment_id', $shipment->id)->count());
    }

    public function test_an_order_is_unbooked_only_when_nothing_of_it_is_loaded(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, $order, [$line]] = $this->orderFor();
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);
        $this->actingAs($user);
        $this->book($shipment, $order, $user);
        $this->receive($line, 4);
        $this->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 4, 'loaded_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->delete(route('shipments.orders.destroy', [$shipment, $order]))->assertStatus(302)->assertSessionHasErrors('supplier_order_id');
        $this->assertSame(1, ShipmentOrder::where('shipment_id', $shipment->id)->count());

        $this->post(route('containers.unloads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 4, 'unloaded_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->delete(route('shipments.orders.destroy', [$shipment, $order]))->assertSessionHasNoErrors();
        $this->assertSame(0, ShipmentOrder::where('shipment_id', $shipment->id)->count());
        $this->assertTrue(AuditLog::where('model_type', Shipment::class)->where('action', 'order_unbooked')->exists());
    }

    public function test_the_database_refuses_to_unbook_an_order_with_goods_loaded(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, $order, [$line]] = $this->orderFor();
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);
        $this->actingAs($user);
        $this->book($shipment, $order, $user);
        $this->receive($line, 2);
        DB::table('container_loads')->insert(['container_id' => $container->id, 'supplier_order_line_id' => $line->id, 'cartons' => 2, 'loaded_on' => now(), 'user_id' => $user->id]);

        foreach ([
            fn () => DB::table('shipment_orders')->where('shipment_id', $shipment->id)->delete(),
            fn () => DB::table('shipment_orders')->where('shipment_id', $shipment->id)->update(['supplier_order_id' => $order->id]),
        ] as $write) {
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
        $this->assertSame(1, ShipmentOrder::where('shipment_id', $shipment->id)->count());
    }

    public function test_containers_are_added_and_edited_with_an_audit(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        $shipment = $this->shipmentWith();

        $this->actingAs($user)->post(route('containers.store', $shipment), ['container_no' => 'TGHU 1234567', 'container_type' => '40HQ'])->assertSessionHasNoErrors();
        $this->post(route('containers.store', $shipment), ['container_no' => 'tghu1234567'])->assertSessionHasErrors('container_no');
        $container = $shipment->containers()->sole();

        $this->put(route('containers.update', $container), ['container_no' => 'TGHU1234567', 'container_type' => '40GP', 'seal_no' => 'ml 998'])
            ->assertSessionHasNoErrors()->assertRedirect(route('containers.show', $container));
        $this->assertSame('ML998', $container->fresh()->seal_no);
        $log = AuditLog::where('model_type', $container::class)->where('model_id', $container->id)->where('action', 'updated')->firstOrFail();
        $this->assertEquals(['container_type' => '40HQ', 'seal_no' => null], $log->old_values);
        $this->assertEquals(['container_type' => '40GP', 'seal_no' => 'ML998'], $log->new_values);
    }
}
