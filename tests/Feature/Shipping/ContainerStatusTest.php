<?php

namespace Tests\Feature\Shipping;

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerEvent;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\LoadsContainers;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** I8: a container's status moves only forward along the list, unless it corrects an event. */
class ContainerStatusTest extends TestCase
{
    use LoadsContainers, RefreshDatabase, SeedsReference, ShippingFixtures;

    private function record(Container $container, string $status, ?int $corrects = null)
    {
        return $this->post(route('containers.events.store', $container), [
            'status' => $status, 'happened_at' => now()->format('Y-m-d\TH:i'), 'place' => 'Yiwu warehouse', 'corrects_event_id' => $corrects,
        ]);
    }

    public function test_a_status_moves_forward(): void
    {
        $container = $this->containerOn($this->shipmentWith());
        $this->actingAs($this->userWithRole(Roles::LOGISTICS));

        foreach (['booked', 'empty_at_warehouse', 'loading'] as $status) {
            $this->record($container, $status)->assertSessionHasNoErrors()->assertRedirect(route('containers.show', $container));
        }
        // B15: a status is recorded once, and sealed only with goods inside.
        $this->record($container, 'loading')->assertSessionHasErrors('status');
        $this->record($container, 'loaded_sealed')->assertSessionHasErrors('status');
        $this->putGoodsIn($container);
        $this->record($container, 'loaded_sealed')->assertSessionHasNoErrors();

        $this->assertSame(['booked', 'empty_at_warehouse', 'loading', 'loaded_sealed'], $container->events()->pluck('status')->all());
        $this->assertSame('loaded_sealed', $container->latestEvent->status);
        $this->assertSame(4, AuditLog::where('model_type', Container::class)->where('action', 'status_recorded')->count());
    }

    public function test_going_back_is_refused_unless_it_corrects_an_event(): void
    {
        $container = $this->containerOn($this->shipmentWith());
        $this->putGoodsIn($container);
        $this->actingAs($this->userWithRole(Roles::LOGISTICS));
        $this->record($container, 'loading');
        $this->travel(1)->minutes();
        $this->record($container, 'gate_in');
        $wrong = ContainerEvent::where('status', 'gate_in')->value('id');

        $this->record($container, 'loaded_sealed')->assertStatus(302)->assertSessionHasErrors('status');
        $this->assertSame(2, $container->events()->count());

        $this->record($container, 'loaded_sealed', $wrong)->assertSessionHasNoErrors();
        $correction = $container->events()->get()->last();
        $this->assertSame('loaded_sealed', $correction->status);
        $this->assertSame($wrong, $correction->corrects_event_id);
        $this->assertTrue(AuditLog::where('model_type', Container::class)->where('action', 'status_corrected')->exists());

        // From the corrected status the path goes on forward.
        $this->travel(1)->minutes();
        $this->record($container, 'gate_in')->assertSessionHasNoErrors();
        $this->get(route('containers.show', $container))->assertOk()->assertSee('Corrects #'.$wrong);
    }

    public function test_a_correction_must_name_an_event_of_the_same_container(): void
    {
        $shipment = $this->shipmentWith();
        $container = $this->containerOn($shipment);
        $other = $this->containerOn($shipment);
        $this->putGoodsIn($container);
        $this->putGoodsIn($other);
        $this->actingAs($this->userWithRole(Roles::LOGISTICS));
        $this->record($other, 'gate_in');
        $this->record($container, 'gate_in');

        $this->record($container, 'booked', ContainerEvent::where('container_id', $other->id)->value('id'))->assertSessionHasErrors('corrects_event_id');
        $this->record($container, 'no_such_status')->assertSessionHasErrors('status');
        $this->assertSame(1, $container->events()->count());
    }
}
