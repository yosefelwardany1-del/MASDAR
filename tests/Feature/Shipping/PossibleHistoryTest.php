<?php

namespace Tests\Feature\Shipping;

use App\Security\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LoadsContainers;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B15: a container's history is a possible one, and a milestone it jumped over reads as skipped. */
class PossibleHistoryTest extends TestCase
{
    use LoadsContainers, RefreshDatabase, SeedsReference, ShippingFixtures;

    private function record($container, string $status, string $at)
    {
        return $this->post(route('containers.events.store', $container), ['status' => $status, 'happened_at' => $at]);
    }

    public function test_impossible_sequences_are_refused_with_plain_messages(): void
    {
        app()->setLocale('en');
        $user = $this->userWithRole(Roles::LOGISTICS, ['locale' => 'en']);
        $this->actingAs($user);
        $empty = $this->containerOn($this->shipmentWith(['etd' => now()->addDays(12)->toDateString()]), ['container_no' => 'MSCU1234560']);

        // Nothing loaded: not delivered, nor sealed.
        $this->record($empty, 'delivered', now()->format('Y-m-d\TH:i'))
            ->assertSessionHasErrors(['status' => 'Nothing is loaded in this container yet. Load its goods before marking it Delivered to client.']);

        // Not in the future; not before the status before it; not twice.
        $this->record($empty, 'booked', '2030-01-01T10:00')
            ->assertSessionHasErrors(['happened_at' => 'A status is recorded once it has happened: the date and time cannot be in the future.']);
        $this->record($empty, 'booked', now()->subDay()->format('Y-m-d\TH:i'))->assertSessionHasNoErrors();
        $this->record($empty, 'loading', '2020-01-01T10:00')->assertSessionHasErrors('happened_at');
        $this->record($empty, 'booked', now()->format('Y-m-d\TH:i'))->assertSessionHasErrors(['status' => 'The container is already Booked.']);
        $this->assertSame(1, $empty->events()->count());

        // The database holds the same rules.
        DB::beginTransaction();
        try {
            DB::table('container_events')->insert(['container_id' => $empty->id, 'status' => 'booked', 'happened_at' => now(), 'user_id' => $user->id]);
            $this->fail('the database took the same status twice');
        } catch (QueryException $e) {
            $this->assertStringContainsString('already recorded', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_milestones_jumped_over_read_as_skipped_not_done(): void
    {
        app()->setLocale('en');
        $user = $this->userWithRole(Roles::LOGISTICS, ['locale' => 'en']);
        $this->actingAs($user);
        $container = $this->containerOn($this->shipmentWith());
        $this->putGoodsIn($container);
        $this->record($container, 'booked', now()->subDays(3)->format('Y-m-d\TH:i'))->assertSessionHasNoErrors();
        $this->record($container, 'arrived', now()->format('Y-m-d\TH:i'))->assertSessionHasNoErrors();

        $page = $this->get(route('containers.show', $container))->assertOk()->getContent();
        $this->assertSame(2, substr_count($page, 'app-stepper__step is-skip'), 'loaded and at sea were never recorded');
        $this->assertStringContainsString('Skipped', $page);
        $this->assertSame(2, substr_count($page, 'app-stepper__step is-done') + substr_count($page, 'app-stepper__step is-current'));
    }
}
