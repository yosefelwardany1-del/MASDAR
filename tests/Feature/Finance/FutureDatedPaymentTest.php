<?php

namespace Tests\Feature\Finance;

use App\Models\Party;
use App\Models\Payment;
use App\Security\Roles;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** B07: a payment is dated today or earlier, by the company's clock. */
class FutureDatedPaymentTest extends FinanceTestCase
{
    public function test_a_payment_or_a_transfer_dated_after_today_is_refused(): void
    {
        app()->setLocale('en');
        $usd = $this->usd();
        $this->setRate('CNY', '2026-01-01', '0.14');
        $tomorrow = now()->addDay()->toDateString();

        $this->assertThrows(fn () => $this->payments()->record(['kind' => 'client_receipt', 'paid_on' => '2030-01-01', 'money_account_id' => $usd->id,
            'amount' => '10', 'party_id' => Party::factory()->client()->create()->id], $this->user(Roles::ACCOUNTANT)), ValidationException::class,
            'A payment cannot be dated after today.');
        $this->actingAs($this->user(Roles::ACCOUNTANT))->post(route('payments.store'), ['kind' => 'client_receipt', 'paid_on' => $tomorrow,
            'money_account_id' => $usd->id, 'amount' => '10', 'party_id' => Party::factory()->client()->create()->id])
            ->assertSessionHasErrors(['paid_on' => 'A payment cannot be dated after today.']);
        $this->actingAs($this->user(Roles::MANAGER))->post(route('payments.transfer.store'), ['from_account_id' => $usd->id, 'to_account_id' => $this->cny()->id,
            'paid_on' => now()->toDateString(), 'in_paid_on' => $tomorrow, 'amount' => '10', 'in_amount' => '70'])
            ->assertSessionHasErrors(['in_paid_on' => 'A payment cannot be dated after today.']);
        $this->assertSame(0, Payment::count());

        // Today counts as today in China (APP_TIMEZONE), late in the evening too.
        $this->travelTo(Carbon::parse(now()->toDateString().' 23:30:00', config('app.timezone')));
        $this->receipt($usd, '10', extra: ['paid_on' => now()->toDateString()]);
        $this->assertSame(1, Payment::count());
    }
}
