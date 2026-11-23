<?php

namespace Tests\Feature\ProformaInvoices;

use App\Models\AuditLog;
use App\Models\MoneyAccount;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Security\Roles;
use App\Services\Finance\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\Feature\Accounting\InteractsWithLedger;
use Tests\TestCase;

/** B03: a PI is confirmed once its deposit has arrived, or by a manager who gives the reason. */
class DepositBeforeConfirmTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    /** An issued PI of 431.36 asking 40%: a deposit of 172.544, so 172.54 to the cent. */
    private function issuedPi(int $deposit = 40): ProformaInvoice
    {
        $pi = ProformaInvoice::factory()->create(['deposit_percent' => $deposit]);
        PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'line_no' => 1, 'cartons' => 10, 'qty_per_carton' => 10, 'unit_price' => '4.3136']);
        $pi->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $pi->created_by]);

        return $pi->fresh();
    }

    private function receive(ProformaInvoice $pi, string $amount): void
    {
        app(PaymentService::class)->record(['kind' => 'client_receipt', 'paid_on' => now()->toDateString(), 'money_account_id' => MoneyAccount::factory()->create()->id,
            'amount' => $amount, 'party_id' => $pi->client_id, 'proforma_invoice_id' => $pi->id], $this->manager());
    }

    public function test_confirm_is_refused_with_nothing_paid_and_allowed_once_the_deposit_is_met(): void
    {
        $sales = $this->userWith(Roles::SALES);
        $pi = $this->issuedPi();
        $pi->client->forceFill(['account_manager_id' => $sales->id])->save();

        $this->actingAs($sales)->get(route('proforma-invoices.show', $pi))->assertOk()
            ->assertSee('Waiting for the deposit')->assertDontSee('Deposit received: confirm');
        $this->actingAs($sales)->post(route('proforma-invoices.confirm', $pi))
            ->assertSessionHasErrors(['pi' => 'The deposit of USD 172.54 has not arrived (USD 0.00 received). Record the client\'s payment first.']);
        $this->assertSame('issued', $pi->fresh()->status);

        $this->receive($pi, '172.53');
        $this->actingAs($sales)->post(route('proforma-invoices.confirm', $pi))->assertSessionHasErrors('pi');

        $this->receive($pi, '0.01');
        $this->actingAs($sales)->get(route('proforma-invoices.show', $pi))->assertSee('Deposit received: confirm');
        $this->actingAs($sales)->post(route('proforma-invoices.confirm', $pi))->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Confirmed: the client\'s deposit has arrived.');
        $this->assertSame('confirmed', $pi->fresh()->status);
    }

    public function test_a_pi_asking_no_deposit_confirms_at_once(): void
    {
        $pi = $this->issuedPi(0);
        $this->actingAs($this->manager())->post(route('proforma-invoices.confirm', $pi))->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $pi->fresh()->status);
    }

    public function test_a_manager_confirms_without_the_deposit_only_with_a_reason_kept_in_the_audit_log(): void
    {
        $manager = $this->manager();
        $sales = $this->userWith(Roles::SALES);
        $pi = $this->issuedPi();
        $pi->client->forceFill(['account_manager_id' => $sales->id])->save();

        $this->actingAs($sales)->post(route('proforma-invoices.confirm', $pi), ['override_reason' => 'Trusted client'])
            ->assertSessionHasErrors(['pi' => 'Only a manager confirms a PI without its deposit.']);

        $this->actingAs($manager)->get(route('proforma-invoices.show', $pi))->assertSee('Confirm without the deposit');
        $this->actingAs($manager)->post(route('proforma-invoices.confirm', $pi), ['override_reason' => 'Long-standing client, pays on loading'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Confirmed without the full deposit. Your reason is kept on the PI and in the audit log.');
        $pi = $pi->fresh();
        $this->assertSame(['confirmed', 'Long-standing client, pays on loading', $manager->id], [$pi->status, $pi->deposit_override_reason, $pi->deposit_override_by]);
        $log = AuditLog::query()->where('model_type', ProformaInvoice::class)->where('model_id', $pi->id)->where('action', 'confirmed')->sole();
        $this->assertSame('Long-standing client, pays on loading', $log->new_values['deposit_override_reason']);
        $this->actingAs($manager)->get(route('proforma-invoices.show', $pi))->assertSee('Confirmed without the full deposit by');
    }
}
