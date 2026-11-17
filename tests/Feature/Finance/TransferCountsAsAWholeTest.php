<?php

namespace Tests\Feature\Finance;

use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\User;
use App\Security\Roles;
use App\Services\Accounting\Ledger;
use App\Services\Finance\PaymentQueries;
use App\Services\Finance\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * While money out needs approval, a transfer between MASDAR's own accounts counts as a whole:
 * the money does not arrive in the cash position (or the ledger) before it may leave.
 */
class TransferCountsAsAWholeTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_the_incoming_leg_waits_for_the_outgoing_legs_approval(): void
    {
        $accountant = User::factory()->create()->assignRole(Roles::ACCOUNTANT);
        $manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $usd = MoneyAccount::factory()->create();
        $rmb = MoneyAccount::factory()->rmb()->create();

        ['out' => $out, 'in' => $in] = app(PaymentService::class)->transfer([
            'from_account_id' => $usd->id, 'to_account_id' => $rmb->id, 'paid_on' => now()->toDateString(),
            'amount' => '1000', 'rate' => '1', 'in_amount' => '7100', 'in_rate' => '0.140845',
        ], $accountant);

        $queries = app(PaymentQueries::class);
        $this->assertFalse($queries->isEffective($out->fresh()));
        $this->assertFalse($queries->isEffective($in->fresh()), 'nothing arrives before it may leave');
        $this->assertSame(0, app(Ledger::class)->effectivePayments()->count());

        app(PaymentService::class)->approve($out->fresh(), $manager);

        $this->assertTrue($queries->isEffective($out->fresh()));
        $this->assertTrue($queries->isEffective($in->fresh()));
        $this->assertSame(2, app(Ledger::class)->effectivePayments()->count());
        $this->assertSame(2, $queries->whereEffective(Payment::query())->count());
    }
}
