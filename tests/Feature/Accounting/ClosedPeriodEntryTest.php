<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\User;
use App\Security\Roles;
use App\Services\Claims\TradeNoteService;
use App\Services\Finance\ExpenseClaimService;
use App\Services\Finance\PaymentService;
use App\Services\Hr\PayrollService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B18: a document dated in a closed period is refused when it is entered, not only when posted. */
class ClosedPeriodEntryTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    public function test_documents_dated_in_a_closed_period_are_refused_at_entry(): void
    {
        $manager = $this->manager();
        $this->travelTo('2026-11-19 10:00:00');
        AccountingPeriod::create(['name' => 'Oct 2026', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'closed_at' => now(), 'closed_by' => $manager->id]);
        $closed = 'The period Oct 2026 (2026-10-01 – 2026-10-31) is closed. Choose a date in an open period, or ask the accountant to reopen it.';
        $usd = MoneyAccount::factory()->create();
        $client = Party::factory()->client()->create();

        // A payment, a transfer.
        $this->assertThrows(fn () => app(PaymentService::class)->record(['kind' => 'client_receipt', 'paid_on' => '2026-10-10', 'money_account_id' => $usd->id,
            'amount' => '100', 'party_id' => $client->id], $manager), ValidationException::class, $closed);
        $this->actingAs($manager)->post(route('payments.store'), ['kind' => 'client_receipt', 'paid_on' => '2026-10-10', 'money_account_id' => $usd->id,
            'amount' => '100', 'party_id' => $client->id])->assertSessionHasErrors(['paid_on' => $closed]);
        $this->assertThrows(fn () => app(PaymentService::class)->transfer(['from_account_id' => $usd->id, 'to_account_id' => MoneyAccount::factory()->create()->id,
            'paid_on' => '2026-10-31', 'amount' => '1'], $manager), ValidationException::class, $closed);

        // A credit note issued on a closed date.
        $pi = $this->confirmedPi();
        $note = app(TradeNoteService::class)->createDraft('credit', ['proforma_invoice_id' => $pi->id, 'reason' => 'x', 'lines' => [['description' => 'x', 'unit_price' => '10']]], $manager);
        $this->assertThrows(fn () => app(TradeNoteService::class)->issue($note, $manager, '2026-10-15'), ValidationException::class, $closed);

        // An expense receipt and a payroll month.
        $claim = app(ExpenseClaimService::class)->create(['currency' => 'CNY', 'title' => 'Trip'], $manager);
        $this->assertThrows(fn () => app(ExpenseClaimService::class)->addLine($claim, ['spent_on' => '2026-10-02', 'category' => 'travel', 'description' => 'Train', 'amount' => '50'], $manager),
            ValidationException::class, $closed);
        $hr = User::factory()->create(['locale' => 'en'])->assignRole(Roles::HR);
        $this->assertThrows(fn () => app(PayrollService::class)->prepare(['period' => '2026-10'], $hr), ValidationException::class, $closed);

        // The database refuses a payment there too; an open date is fine.
        DB::beginTransaction();
        try {
            DB::table('payments')->insert(['number' => 'PAY-X', 'paid_on' => '2026-10-10', 'kind' => 'client_receipt', 'direction' => 'in',
                'money_account_id' => $usd->id, 'currency' => 'USD', 'amount' => '1', 'rate' => '1', 'user_id' => $manager->id, 'party_id' => $client->id]);
            $this->fail('the database took a payment in a closed period');
        } catch (QueryException $e) {
            $this->assertStringContainsString('is closed', $e->getMessage());
        } finally {
            DB::rollBack();
        }
        app(PaymentService::class)->record(['kind' => 'client_receipt', 'paid_on' => '2026-11-02', 'money_account_id' => $usd->id, 'amount' => '100', 'party_id' => $client->id], $manager);
        $this->assertSame(1, Payment::count());
    }
}
