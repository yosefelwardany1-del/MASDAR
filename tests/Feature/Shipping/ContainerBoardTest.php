<?php

namespace Tests\Feature\Shipping;

use App\Models\ContainerEvent;
use App\Models\Party;
use App\Models\User;
use App\Security\Roles;
use App\Services\Shipping\ContainerTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** The container board (spec §5.5, §7): status, ETA flags, clients inside, fill. */
class ContainerBoardTest extends TestCase
{
    use RefreshDatabase, SeedsReference, ShippingFixtures;

    private function setStatus($container, string $status, User $by): void
    {
        app(ContainerTrackingService::class)->recordStatus($container, ['status' => $status, 'happened_at' => now()->toDateTimeString()], $by);
    }

    /** Puts $cartons of a new order for $client into $container (booked and received first). */
    private function fill($container, Party $client, int $cartons, string $cbm, User $by): void
    {
        [, $order, [$line]] = $this->orderFor($client, [['cartons' => $cartons, 'cbm' => $cbm, 'kg' => '20.0000']]);
        $this->book($container->shipment, $order, $by);
        $this->receive($line, $cartons);
        app(ContainerTrackingService::class)->load($container, $line, (string) $cartons, now()->toDateString(), $by);
    }

    public function test_the_board_shows_status_clients_and_fill(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        $this->actingAs($user);
        $container = $this->containerOn($this->shipmentWith(), ['container_no' => 'MSKU7654321', 'container_type' => '40HQ']); // 68 CBM
        $this->fill($container, Party::factory()->client()->create(['name' => 'Al Noor Trading']), 20, '1.0000', $user);
        $this->fill($container, Party::factory()->client()->create(['name' => 'Gulf Home LLC']), 14, '1.0000', $user);
        $this->setStatus($container, 'loaded_sealed', $user);
        $empty = $this->containerOn($this->shipmentWith(), ['container_no' => 'TGHU0000001', 'container_type' => 'LCL']);

        $this->get(route('containers.index'))->assertOk()
            ->assertSee('MSKU7654321')->assertSee('TGHU0000001')
            ->assertSee('Loaded &amp; sealed', false)->assertSee('No status yet')
            ->assertSee('Al Noor Trading')->assertSee('Gulf Home LLC')
            ->assertSee('50%')           // 34 of 68 CBM
            ->assertSee('680');          // 34 cartons × 20 KG

        $this->get(route('containers.index', ['status' => 'loaded_sealed']))->assertSee('MSKU7654321')->assertDontSee('TGHU0000001');
        $this->get(route('containers.index', ['status' => '_none']))->assertDontSee('MSKU7654321')->assertSee('TGHU0000001');
        $noor = Party::where('name', 'Al Noor Trading')->value('id');
        $this->get(route('containers.index', ['client_id' => $noor]))->assertSee('MSKU7654321')->assertDontSee('TGHU0000001');
        $this->get(route('containers.index', ['shipping_company_id' => $empty->shipment->shipping_company_id]))->assertDontSee('MSKU7654321')->assertSee('TGHU0000001');
        $this->get(route('containers.show', $container))->assertOk()->assertSee('Al Noor Trading')->assertSee('50%');
    }

    public function test_late_and_arriving_soon_flags_and_filters(): void
    {
        $user = $this->userWithRole(Roles::LOGISTICS);
        $this->actingAs($user);
        $late = $this->containerOn($this->shipmentWith(['etd' => now()->subDays(30)->toDateString(), 'eta' => now()->subDays(3)->toDateString()]), ['container_no' => 'LATE0000001']);
        $soon = $this->containerOn($this->shipmentWith(['etd' => now()->subDays(20)->toDateString(), 'eta' => now()->addDays(2)->toDateString()]), ['container_no' => 'SOON0000001']);
        $arrived = $this->containerOn($this->shipmentWith(['etd' => now()->subDays(40)->toDateString(), 'eta' => now()->subDays(5)->toDateString()]), ['container_no' => 'HOME0000001']);
        $far = $this->containerOn($this->shipmentWith(['eta' => now()->addDays(30)->toDateString()]), ['container_no' => 'FARR0000001']);
        $this->setStatus($late, 'in_transit', $user);
        $this->setStatus($arrived, 'arrived', $user);

        $this->get(route('containers.index'))->assertOk()
            ->assertSee('Late by 3 days')->assertSee('Arriving in 2 days')->assertDontSee('Late by 5 days');

        $this->get(route('containers.index', ['late' => 1]))
            ->assertSee('LATE0000001')->assertDontSee('SOON0000001')->assertDontSee('HOME0000001')->assertDontSee('FARR0000001');
        $this->get(route('containers.index', ['soon' => 1]))
            ->assertSee('SOON0000001')->assertDontSee('LATE0000001')->assertDontSee('HOME0000001')->assertDontSee('FARR0000001');
        $this->get(route('containers.show', $late))->assertSee('Late by 3 days');

        // Released and delivered count as arrived too; a correction back before arrival does not.
        $this->setStatus($arrived, 'delivered', $user);
        $this->get(route('containers.index', ['late' => 1]))->assertDontSee('HOME0000001');
        app(ContainerTrackingService::class)->recordStatus($arrived, [
            'status' => 'in_transit', 'happened_at' => now()->toDateTimeString(),
            'corrects_event_id' => ContainerEvent::where('container_id', $arrived->id)->where('status', 'delivered')->value('id'),
        ], $user);
        $this->get(route('containers.index', ['late' => 1]))->assertSee('HOME0000001');
    }

    public function test_a_sales_user_sees_only_containers_holding_their_own_clients_goods(): void
    {
        $logistics = $this->userWithRole(Roles::LOGISTICS);
        $sales = $this->userWithRole(Roles::SALES);
        $mine = Party::factory()->client()->create(['name' => 'Mine Imports', 'account_manager_id' => $sales->id]);
        $theirs = Party::factory()->client()->create(['name' => 'Theirs Wholesale']);
        $this->actingAs($logistics);
        $shared = $this->containerOn($this->shipmentWith(), ['container_no' => 'SHRD0000001']);
        $other = $this->containerOn($this->shipmentWith(), ['container_no' => 'OTHR0000001']);
        $this->fill($shared, $mine, 5, '1.0000', $logistics);
        $this->fill($shared, $theirs, 5, '1.0000', $logistics);
        $this->fill($other, $theirs, 5, '1.0000', $logistics);

        $this->actingAs($sales)->get(route('containers.index'))->assertOk()
            ->assertSee('SHRD0000001')->assertDontSee('OTHR0000001')
            ->assertSee('Mine Imports')->assertDontSee('Theirs Wholesale');
        $this->get(route('containers.show', $shared))->assertOk()->assertSee('Mine Imports')->assertDontSee('Theirs Wholesale');
        $this->get(route('containers.show', $other))->assertNotFound();
        $this->get(route('containers.packing-list', $other))->assertNotFound();
        $this->get(route('shipments.index'))->assertSee($shared->shipment->number)->assertDontSee($other->shipment->number);
        $this->get(route('shipments.show', $other->shipment))->assertNotFound();

        $this->actingAs($logistics)->get(route('containers.index'))->assertSee('SHRD0000001')->assertSee('OTHR0000001');
    }

    public function test_the_screens_render_in_arabic_with_data(): void
    {
        $user = $this->userWithRole(Roles::MANAGER, ['locale' => 'ar']);
        $this->actingAs($user);
        $shipment = $this->shipmentWith(['eta' => now()->subDays(2)->toDateString()]);
        $container = $this->containerOn($shipment, ['container_no' => 'ARAB0000001']);
        $this->fill($container, Party::factory()->client()->create(['name' => 'Al Noor Trading']), 5, '1.0000', $user);
        $this->setStatus($container, 'loading', $user);

        $this->get(route('containers.index'))->assertOk()->assertSee('ARAB0000001')->assertSee('جاري التحميل')->assertSee('bi-arrow-left', false);
        $this->get(route('containers.show', $container))->assertOk()->assertSee('Al Noor Trading');
        $this->get(route('shipments.show', $shipment))->assertOk()->assertSee('ARAB0000001');
    }
}
