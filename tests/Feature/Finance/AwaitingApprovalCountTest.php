<?php

namespace Tests\Feature\Finance;

use App\Models\Party;
use App\Models\Payment;
use App\Security\Roles;
use App\Services\Accounting\PostingQueue;
use App\Services\Dashboard\AttentionQueries;
use App\Services\Finance\PaymentQueries;

/** B17: "awaiting approval" is one count everywhere; a payment withdrawn before approval waits for nothing. */
class AwaitingApprovalCountTest extends FinanceTestCase
{
    public function test_to_post_payments_and_home_agree_and_a_withdrawn_payment_is_not_awaiting(): void
    {
        app()->setLocale('en');
        $this->setRate('CNY', now()->toDateString(), '0.14');
        $accountant = $this->user(Roles::ACCOUNTANT);
        $manager = $this->user(Roles::MANAGER);
        $manager->forceFill(['locale' => 'en'])->save();
        $pay = fn (string $amount) => $this->payments()->record(['kind' => 'supplier_payment', 'paid_on' => now()->toDateString(),
            'money_account_id' => $this->cny()->id, 'amount' => $amount, 'party_id' => Party::factory()->supplier()->create()->id], $accountant);

        $waiting = $pay('100');
        $withdrawn = $pay('200');
        [$withdrawal] = $this->payments()->reverse($withdrawn, $accountant, 'Wrong factory');

        $counts = [
            app(PostingQueue::class)->awaitingApproval(),
            app(PaymentQueries::class)->whereAwaitingApproval(Payment::query())->count(),
            app(AttentionQueries::class)->counts()['awaiting_approval'],
        ];
        $this->assertSame([1, 1, 1], $counts, 'To post, the Payments filter and Home');
        $this->actingAs($manager)->get(route('payments.index', ['awaiting' => 1]))->assertSee($waiting->number)->assertDontSee($withdrawn->number);
        $this->actingAs($manager)->get(route('accounting.to-post.index'))->assertSee('One payment out is awaiting a manager');

        $this->assertSame(['withdrawn', 'withdrawal'], [app(PaymentQueries::class)->status($withdrawn->fresh()), app(PaymentQueries::class)->status($withdrawal->fresh())]);
        $this->actingAs($manager)->get(route('payments.show', $withdrawn))->assertSee('Withdrawn');
        $this->actingAs($manager)->get(route('payments.show', $waiting))->assertSee('Withdraw this payment? It was never approved, so it never counted.');
    }
}
