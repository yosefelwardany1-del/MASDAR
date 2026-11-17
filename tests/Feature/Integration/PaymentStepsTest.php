<?php

namespace Tests\Feature\Integration;

use App\Models\BusinessSetting;
use App\Models\MoneyAccount;
use App\Models\OrderStepDefinition;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Security\Roles;
use App\Services\Finance\FxRateService;
use App\Services\Finance\PaymentService;
use App\Services\SupplierOrders\OrderStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Paying a factory moves its order's payment steps: the deposit once something is paid, the
 * final transfer once everything is — counting only money that counts (approved when approval
 * is on), and stepping back when a payment is reversed.
 */
class PaymentStepsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private SupplierOrder $order;

    private MoneyAccount $rmb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create()->assignRole(Roles::MANAGER);
        // 10 cartons × 48 × 6.50 = 3,120.00 CNY
        $this->order = SupplierOrderLine::factory()->create(['cartons' => 10, 'qty_per_carton' => 48, 'unit_price' => '6.5000'])->supplierOrder;
        $this->rmb = MoneyAccount::factory()->rmb()->create();
        app(FxRateService::class)->set('CNY', now()->subDay()->toDateString(), '0.140000', $this->manager);
    }

    private function pay(string $amount)
    {
        return app(PaymentService::class)->record([
            'kind' => 'supplier_payment', 'paid_on' => now()->toDateString(), 'money_account_id' => $this->rmb->id,
            'amount' => $amount, 'party_id' => $this->order->supplier_id, 'supplier_order_id' => $this->order->id,
        ], $this->manager);
    }

    private function state(string $code): ?string
    {
        return app(OrderStepService::class)->latestEvents($this->order)->get(OrderStepDefinition::where('code', $code)->value('id'))?->status;
    }

    public function test_the_deposit_then_the_final_transfer_follow_the_money_once_approved(): void
    {
        $deposit = $this->pay('936.00');
        $this->assertNull($this->state('deposit'), 'awaiting approval: it does not count yet');

        app(PaymentService::class)->approve($deposit, $this->manager);
        $this->assertSame('done', $this->state('deposit'));
        $this->assertNull($this->state('final_payment'));

        app(PaymentService::class)->approve($this->pay('2184.00'), $this->manager);
        $this->assertSame('done', $this->state('final_payment'));
    }

    public function test_without_approval_a_payment_moves_the_steps_at_once_and_a_reversal_steps_back(): void
    {
        BusinessSetting::current()->fill(['payments_out_require_approval' => false])->save();

        $all = $this->pay('3120.00');
        $this->assertSame('done', $this->state('deposit'));
        $this->assertSame('done', $this->state('final_payment'));

        app(PaymentService::class)->reverse($all, $this->manager, 'Paid the wrong factory');
        $this->assertSame('reopened', $this->state('deposit'));
        $this->assertSame('reopened', $this->state('final_payment'));
    }

    public function test_a_step_marked_done_by_hand_is_not_undone_by_a_partial_payment(): void
    {
        BusinessSetting::current()->fill(['payments_out_require_approval' => false])->save();
        app(OrderStepService::class)->markDone($this->order, OrderStepDefinition::where('code', 'final_payment')->first(), $this->manager, now()->toDateString(), 'Paid in cash at the market');

        $this->pay('500.00');
        $this->assertSame('done', $this->state('deposit'));
        $this->assertSame('done', $this->state('final_payment'));
    }
}
