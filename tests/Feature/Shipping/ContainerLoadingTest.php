<?php

namespace Tests\Feature\Shipping;

use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\ContainerLoad;
use App\Models\OrderStepDefinition;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderStepEvent;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Loads (I6: never more than received), the loading step, capacity and the unpaid-PI rule (I9). */
class ContainerLoadingTest extends TestCase
{
    use RefreshDatabase, SeedsReference, ShippingFixtures;

    private function loaded(Container $container): string
    {
        return (string) DB::table('container_loads')->where('container_id', $container->id)->sum('cartons');
    }

    private function loadingStepEvents(SupplierOrder $order)
    {
        return SupplierOrderStepEvent::where('supplier_order_id', $order->id)
            ->where('step_definition_id', OrderStepDefinition::where('code', 'loading')->value('id'))->get();
    }

    public function test_loading_within_what_was_received_works_and_is_audited(): void
    {
        $user = $this->userWithRole(Roles::WAREHOUSE_QC);
        [, $order, [$line]] = $this->orderFor();
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);
        $this->actingAs($user);
        $this->book($shipment, $order, $user);
        $this->receive($line, 6);

        $this->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 4, 'loaded_on' => now()->toDateString()])
            ->assertSessionHasNoErrors()->assertRedirect(route('containers.show', $container));

        $load = ContainerLoad::where('container_id', $container->id)->sole();
        $this->assertSame('4.0000', $load->cartons);
        $this->assertSame($user->id, $load->user_id);
        $this->assertTrue(AuditLog::where('model_type', Container::class)->where('model_id', $container->id)->where('action', 'loaded')->exists());
        $this->assertCount(0, $this->loadingStepEvents($order), 'two received cartons are still waiting');
    }

    public function test_loading_beyond_what_was_received_is_a_validation_error_not_a_crash(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, $order, [$line]] = $this->orderFor();
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);
        $other = $this->containerOn($shipment);
        $this->actingAs($user);
        $this->book($shipment, $order, $user);
        $this->receive($line, 6);
        $this->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 4, 'loaded_on' => now()->toDateString()]);

        $this->from(route('containers.show', $other))
            ->post(route('containers.loads.store', $other), ['supplier_order_line_id' => $line->id, 'cartons' => 3, 'loaded_on' => now()->toDateString()])
            ->assertStatus(302)->assertRedirect(route('containers.show', $other))
            ->assertSessionHasErrors(['cartons' => 'Only 2 cartons of this line are received and not yet loaded (6 received, 4 loaded).']);

        $this->assertSame('4.0000', $this->loaded($container));
        $this->assertSame('0', $this->loaded($other));
    }

    public function test_a_line_loads_only_once_its_order_is_booked_on_the_shipment(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, , [$line]] = $this->orderFor();
        $container = $this->containerOn($this->shipmentWith());
        $this->receive($line, 6);

        $this->actingAs($user)->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 1, 'loaded_on' => now()->toDateString()])
            ->assertSessionHasErrors('supplier_order_line_id');
        $this->assertSame(0, ContainerLoad::count());
    }

    public function test_when_every_received_carton_is_loaded_the_loading_step_is_done(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, $order, [$first, $second]] = $this->orderFor(null, [['cartons' => 10], ['cartons' => 8]]);
        $shipment = $this->shipmentWith();
        $a = $this->containerOn($shipment);
        $b = $this->containerOn($shipment);
        $this->actingAs($user);
        $this->book($shipment, $order, $user);
        $this->receive($first, 5);
        $this->receive($second, 3);

        $this->post(route('containers.loads.store', $a), ['supplier_order_line_id' => $first->id, 'cartons' => 5, 'loaded_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertCount(0, $this->loadingStepEvents($order));

        $this->post(route('containers.loads.store', $b), ['supplier_order_line_id' => $second->id, 'cartons' => 3, 'loaded_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $events = $this->loadingStepEvents($order);
        $this->assertCount(1, $events);
        $this->assertSame('done', $events[0]->status);

        // More arrives and is loaded too: the step is not recorded twice.
        $this->receive($second, 2);
        $this->post(route('containers.loads.store', $b), ['supplier_order_line_id' => $second->id, 'cartons' => 2, 'loaded_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->loadingStepEvents($order));
    }

    public function test_going_over_capacity_warns_but_loads(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, $order, [$line]] = $this->orderFor(null, [['cartons' => 30, 'cbm' => '1.0000']]);
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment, ['container_type' => '20GP']); // 28 CBM
        $this->actingAs($user);
        $this->book($shipment, $order, $user);
        $this->receive($line, 30);

        $this->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 30, 'loaded_on' => now()->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', 'The container is over its capacity: 30 CBM loaded of 28 CBM.');
        $this->assertSame('30.0000', $this->loaded($container));
        $this->get(route('containers.show', $container))->assertOk()->assertSee('107%');
    }

    public function test_an_unload_is_a_negative_row_and_never_more_than_the_container_holds(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, $order, [$line]] = $this->orderFor();
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);
        $this->actingAs($user);
        $this->book($shipment, $order, $user);
        $this->receive($line, 6);
        $this->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 6, 'loaded_on' => now()->toDateString()]);

        $this->post(route('containers.unloads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 2, 'unloaded_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->post(route('containers.unloads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 5, 'unloaded_on' => now()->toDateString()])
            ->assertStatus(302)->assertSessionHasErrors(['cartons' => 'This container holds only 4 cartons of this line.']);

        $this->assertSame(['6.0000', '-2.0000'], ContainerLoad::orderBy('id')->pluck('cartons')->all());
        $this->assertTrue(AuditLog::where('model_type', Container::class)->where('action', 'unloaded')->exists());
    }

    public function test_block_loading_unpaid_refuses_an_unpaid_pi_and_lets_a_manager_through(): void
    {
        BusinessSetting::current()->fill(['block_loading_unpaid' => true])->save();
        $logistics = $this->userWithRole(Roles::LOGISTICS);
        $manager = $this->userWithRole(Roles::MANAGER);
        [$pi, $order, [$line]] = $this->orderFor(null, [['cartons' => 10, 'price' => '100.0000']]); // 10 × 12 × 100 = 12,000
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);
        $this->book($shipment, $order, $logistics);
        $this->receive($line, 10);
        $this->pay($pi, '3600');

        $this->actingAs($logistics)
            ->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 2, 'loaded_on' => now()->toDateString()])
            ->assertSessionHasErrors(['supplier_order_line_id' => 'PI '.$pi->pi_number.' is not fully paid (3,600 of 12,000 USD received). Only a manager may load it.']);
        $this->assertSame(0, ContainerLoad::count());

        $this->actingAs($manager)
            ->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 2, 'loaded_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $exception = AuditLog::where('model_type', Container::class)->where('action', 'loading_exception')->sole();
        $this->assertSame($manager->id, $exception->user_id);
        $this->assertSame($pi->pi_number, $exception->new_values['pi_number']);

        // Once paid in full (over any revision), anyone with the screen may load it.
        $this->pay($pi, '8400');
        $this->actingAs($logistics)
            ->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 3, 'loaded_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame('5.0000', $this->loaded($container));
        $this->assertSame(1, AuditLog::where('action', 'loading_exception')->count());
    }

    public function test_with_the_rule_off_an_unpaid_pi_loads(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        [, $order, [$line]] = $this->orderFor();
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);
        $this->book($shipment, $order, $user);
        $this->receive($line, 1);

        $this->actingAs($user)
            ->post(route('containers.loads.store', $container), ['supplier_order_line_id' => $line->id, 'cartons' => 1, 'loaded_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, ContainerLoad::count());
    }
}
