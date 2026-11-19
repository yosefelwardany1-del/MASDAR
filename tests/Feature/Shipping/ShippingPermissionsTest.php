<?php

namespace Tests\Feature\Shipping;

use App\Models\Container;
use App\Models\Party;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Screen `containers`: view for the board and pages, edit for every write (spec §6). */
class ShippingPermissionsTest extends TestCase
{
    use RefreshDatabase, SeedsReference, ShippingFixtures;

    public function test_staff_is_refused_everywhere(): void
    {
        $staff = $this->userWithRole(Roles::STAFF);
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);

        $this->actingAs($staff);
        $this->get(route('containers.index'))->assertForbidden();
        $this->get(route('containers.show', $container))->assertForbidden();
        $this->get(route('containers.packing-list', $container))->assertForbidden();
        $this->get(route('shipments.index'))->assertForbidden();
        $this->get(route('shipments.show', $shipment))->assertForbidden();
        $this->get(route('shipments.create'))->assertForbidden();
        $this->post(route('shipments.store'), ['shipping_company_id' => $shipment->shipping_company_id])->assertForbidden();
        $this->post(route('containers.store', $shipment), ['container_no' => 'ABCD1234567'])->assertForbidden();
        $this->post(route('containers.events.store', $container), ['status' => 'booked', 'happened_at' => now()->toDateTimeString()])->assertForbidden();
        $this->assertSame(1, Container::count());
    }

    public function test_sales_may_look_but_not_change(): void
    {
        $sales = $this->userWithRole(Roles::SALES);
        $shipment = $this->shipmentWith();

        $this->actingAs($sales)->get(route('containers.index'))->assertOk();
        $this->get(route('shipments.index'))->assertOk();
        $this->get(route('shipments.create'))->assertForbidden();
        $this->post(route('containers.store', $shipment), ['container_no' => 'ABCD1234567'])->assertForbidden();
    }

    public function test_logistics_and_warehouse_qc_can_edit(): void
    {
        // Two real container numbers (ISO 6346 check digits, B28), typed in lower case with a space.
        foreach ([Roles::LOGISTICS => 'msku 7712342', Roles::WAREHOUSE_QC => 'cosu 6300114'] as $role => $number) {
            $user = $this->userWithRole($role);
            $company = Party::factory()->shippingCompany()->create();

            $this->actingAs($user)->post(route('shipments.store'), ['shipping_company_id' => $company->id, 'mode' => 'sea_fcl'])
                ->assertSessionHasNoErrors()->assertRedirect();
            $shipment = $company->shipments()->firstOrFail();

            $this->post(route('containers.store', $shipment), ['container_no' => $number, 'container_type' => '40HQ', 'seal_no' => 'S-1'])
                ->assertSessionHasNoErrors()->assertRedirect(route('shipments.show', $shipment));
            $container = $shipment->containers()->firstOrFail();
            $this->assertSame(strtoupper(str_replace(' ', '', $number)), $container->container_no);

            $this->post(route('containers.events.store', $container), ['status' => 'booked', 'happened_at' => now()->format('Y-m-d\TH:i')])
                ->assertSessionHasNoErrors();
            $this->assertSame(1, $container->events()->count(), $role);
        }
    }

    public function test_every_screen_renders_for_a_manager(): void
    {
        $manager = $this->userWithRole(Roles::MANAGER);
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);

        $this->actingAs($manager);
        foreach ([
            route('containers.index'), route('containers.show', $container), route('containers.edit', $container),
            route('containers.packing-list', $container), route('shipments.index'), route('shipments.create'),
            route('shipments.show', $shipment), route('shipments.edit', $shipment),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
