<?php

namespace Tests\Feature\Finance;

use App\Models\BusinessSetting;
use App\Models\Party;
use App\Models\PaymentApproval;
use App\Security\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** B16: nobody approves their own payment, unless a single-manager company switches that off. */
class ApproveOwnPaymentTest extends FinanceTestCase
{
    public function test_the_approver_is_not_the_person_who_recorded_it(): void
    {
        app()->setLocale('en');
        $this->setRate('CNY', now()->toDateString(), '0.14');
        $manager = $this->user(Roles::MANAGER);
        $other = $this->user(Roles::MANAGER);
        $payment = $this->payments()->record(['kind' => 'supplier_payment', 'paid_on' => now()->toDateString(), 'money_account_id' => $this->cny()->id,
            'amount' => '500', 'party_id' => Party::factory()->supplier()->create()->id], $manager);

        $this->assertThrows(fn () => $this->payments()->approve($payment, $manager), ValidationException::class, 'You recorded this payment, so another manager approves it.');
        $this->actingAs($manager)->post(route('payments.approve', $payment))->assertSessionHasErrors('payment');
        DB::beginTransaction();
        try {
            DB::table('payment_approvals')->insert(['payment_id' => $payment->id, 'approved_by' => $manager->id]);
            $this->fail('the database let someone approve their own payment');
        } catch (QueryException $e) {
            $this->assertStringContainsString('someone other than the person who recorded it', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->payments()->approve($payment, $other);
        $this->assertSame($other->id, PaymentApproval::where('payment_id', $payment->id)->value('approved_by'));

        // A company with one manager switches the rule off.
        BusinessSetting::current()->fill(['payments_approved_by_another' => false])->save();
        $own = $this->payments()->record(['kind' => 'supplier_payment', 'paid_on' => now()->toDateString(), 'money_account_id' => $this->cny()->id,
            'amount' => '100', 'party_id' => Party::factory()->supplier()->create()->id], $manager);
        $this->payments()->approve($own, $manager);
        $this->assertTrue(PaymentApproval::where('payment_id', $own->id)->exists());
    }
}
