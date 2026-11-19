<?php

namespace Tests\Feature\Claims;

use App\Models\Party;
use App\Models\User;
use App\Security\Roles;
use App\Services\Claims\ClaimService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B14: the amount agreed is above zero and at most what was claimed, said next to the field. */
class AgreedAmountTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_the_agreed_amount_is_above_zero_and_at_most_the_claimed_amount(): void
    {
        app()->setLocale('en');
        $manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
        $claim = app(ClaimService::class)->open(['kind' => 'defect', 'client_id' => Party::factory()->client()->create()->id,
            'description' => 'Broken jars', 'claimed_amount' => '100', 'currency' => 'USD'], $manager);

        $this->actingAs($manager)->post(route('claims.move', $claim), ['status' => 'agreed', 'agreed_amount' => '99999'])
            ->assertSessionHasErrors(['agreed_amount' => 'The amount agreed can\'t be more than the 100.00 USD claimed.']);
        $this->actingAs($manager)->post(route('claims.move', $claim), ['status' => 'agreed', 'agreed_amount' => '-5'])
            ->assertSessionHasErrors(['agreed_amount' => 'Enter a number of up to 2 decimals, without a minus sign.']);
        $this->actingAs($manager)->post(route('claims.move', $claim), ['status' => 'agreed', 'agreed_amount' => '0'])
            ->assertSessionHasErrors(['agreed_amount' => 'Enter an amount above zero.']);
        // The message shows next to the field.
        $this->actingAs($manager)->followingRedirects()->from(route('claims.show', $claim))
            ->post(route('claims.move', $claim), ['status' => 'agreed', 'agreed_amount' => '150'])
            ->assertSee('The amount agreed can&#039;t be more than the 100.00 USD claimed.', false)->assertSee('is-invalid');

        $this->actingAs($manager)->post(route('claims.move', $claim), ['status' => 'agreed', 'agreed_amount' => '100'])->assertSessionHasNoErrors();
        $this->assertSame('100.0000', $claim->fresh()->agreedAmount());

        DB::beginTransaction();
        try {
            DB::table('claim_events')->insert(['claim_id' => $claim->id, 'status' => 'agreed', 'agreed_amount' => '100.01', 'user_id' => $manager->id]);
            $this->fail('the database took an amount agreed above the claim');
        } catch (QueryException $e) {
            $this->assertStringContainsString('more than the amount claimed', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
