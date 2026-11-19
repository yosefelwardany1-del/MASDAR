<?php

namespace Tests\Feature\Documents;

use App\Models\Party;
use App\Models\Quotation;
use App\Models\User;
use App\Security\Roles;
use App\Services\Quotations\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B13: past its "valid until" a quotation is not accepted nor made a PI; sending one past it is on purpose. */
class ExpiredQuotationTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function quotation(User $by, string $validUntil): Quotation
    {
        $service = app(QuotationService::class);
        $q = $service->create(['client_id' => Party::factory()->client()->create()->id, 'currency' => 'USD', 'quoted_on' => '2026-01-01', 'valid_until' => $validUntil], $by);
        $service->addLine($q, ['description' => 'Jar', 'unit' => 'pcs', 'unit_price' => '1.20'], $by);

        return $q->fresh();
    }

    public function test_an_expired_quotation_is_not_accepted_or_converted_and_shows_under_expired(): void
    {
        app()->setLocale('en');
        $manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
        $service = app(QuotationService::class);

        // Sending a draft whose date has passed asks first.
        $old = $this->quotation($manager, '2026-01-31');
        $this->assertThrows(fn () => $service->send($old, $manager), ValidationException::class, 'This quotation was valid until 2026-01-31, which has passed.');
        $this->actingAs($manager)->get(route('quotations.show', $old))->assertSee('Send it anyway?');
        $this->actingAs($manager)->post(route('quotations.send', $old), ['anyway' => '1'])->assertSessionHasNoErrors();
        $old = $old->fresh();
        $this->assertSame('sent', $old->status);
        $this->assertTrue($old->isExpired());

        // Not accepted, not made a PI; declining is still recorded.
        $message = 'This quotation expired on 2026-01-31. Copy it (Copy to revise), give the copy a new date and send that instead.';
        $this->actingAs($manager)->post(route('quotations.accept', $old))->assertSessionHasErrors(['quotation' => $message]);
        $this->actingAs($manager)->post(route('quotations.convert', $old))->assertSessionHasErrors(['quotation' => $message]);
        $this->actingAs($manager)->get(route('quotations.show', $old))->assertSee($message)
            ->assertDontSee(route('quotations.accept', $old), false)->assertDontSee(route('quotations.convert', $old), false);

        // The Expired tab finds what the badge calls expired, and the Sent tab does not.
        $live = $this->quotation($manager, now()->addDays(10)->toDateString());
        $service->send($live, $manager);
        $this->assertSame([$old->id], Quotation::query()->shownAs('expired')->pluck('id')->all());
        $this->assertSame([$live->id], Quotation::query()->shownAs('sent')->pluck('id')->all());
        $this->actingAs($manager)->get(route('quotations.index', ['status' => 'expired']))->assertSee($old->number)->assertDontSee($live->number);

        $service->decline($old->fresh(), $manager);
        $this->assertSame('declined', $old->fresh()->status);
        // Today is still valid.
        $today = $this->quotation($manager, now()->toDateString());
        $service->send($today, $manager);
        $service->accept($today->fresh(), $manager);
        $this->assertSame('accepted', $today->fresh()->status);
    }
}
