<?php

namespace Tests\Feature\Shipping;

use App\Models\Party;
use App\Models\Setting;
use App\Security\Roles;
use App\Services\Shipping\ContainerTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** The packing list, produced from the loads (spec §5.5): English, with lines and totals. */
class PackingListTest extends TestCase
{
    use RefreshDatabase, SeedsReference, ShippingFixtures;

    public function test_the_packing_list_shows_the_lines_and_totals(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS, ['locale' => 'ar']);
        $this->actingAs($user);
        Setting::current()->fill(['company_name' => 'MASDAR FOR TRADING CO., LIMITED'])->save();
        $shipment = $this->shipmentWith(['vessel' => 'COSCO PRIDE', 'voyage' => '088E', 'bl_number' => 'COSU6300112', 'port_of_loading' => 'NGB', 'port_of_discharge' => 'JEA']);
        $container = $this->containerOn($shipment, ['container_no' => 'CSNU1234567', 'container_type' => '40HQ', 'seal_no' => 'SL778899']);
        [$pi, $order, [$cups, $plates]] = $this->orderFor(Party::factory()->client()->create(['name' => 'Al Noor Trading']), [
            ['cartons' => 20, 'cbm' => '0.1250', 'kg' => '8.5000', 'name' => 'Glass cups'],
            ['cartons' => 10, 'cbm' => '0.2000', 'kg' => '12.0000', 'name' => 'Ceramic plates'],
        ]);
        $this->book($shipment, $order, $user);
        $this->receive($cups, 20);
        $this->receive($plates, 10);
        $tracking = app(ContainerTrackingService::class);
        $tracking->load($container, $cups, '20', now()->toDateString(), $user);
        $tracking->load($container, $plates, '10', now()->toDateString(), $user);
        $tracking->unload($container, $plates, '2', now()->toDateString(), $user);

        $this->get(route('containers.packing-list', $container))->assertOk()
            ->assertSee('PACKING LIST')->assertSee('MASDAR FOR TRADING CO., LIMITED')
            ->assertSee('CSNU1234567')->assertSee('SL778899')->assertSee('COSU6300112')->assertSee('COSCO PRIDE')
            ->assertSee('Ningbo')->assertSee('Jebel Ali')->assertDontSee('نينغبو')
            ->assertSee('Al Noor Trading')->assertSee($pi->pi_number)->assertSee('Glass cups')->assertSee('Ceramic plates')
            ->assertSeeInOrder(['Glass cups', '20', '240', '2.5', '170'])        // 20 CTN × 12, × 0.125 CBM, × 8.5 KG
            ->assertSeeInOrder(['Ceramic plates', '8', '96', '1.6', '96'])      // 10 − 2 unloaded
            ->assertSeeInOrder(['TOTAL', '28', '336', '4.1', '266']);
    }
}
