<?php

namespace Tests\Feature\ProformaInvoices;

use App\Models\MoneyAccount;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Security\Roles;
use App\Services\Finance\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\Feature\Accounting\InteractsWithLedger;
use Tests\TestCase;

/** B02: a PI that money or the books rely on is not cancelled until they are dealt with. */
class CancelGuardTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    private function cancel(ProformaInvoice $pi, $user = null)
    {
        return $this->actingAs($user ?? $this->manager())->post(route('proforma-invoices.cancel', $pi), ['cancel_reason' => 'Client withdrew']);
    }

    public function test_a_pi_with_money_received_is_not_cancelled_until_the_receipt_is_reversed(): void
    {
        $manager = $this->manager();
        $pi = $this->confirmedPi();
        $receipt = app(PaymentService::class)->record(['kind' => 'client_receipt', 'paid_on' => now()->toDateString(), 'money_account_id' => MoneyAccount::factory()->create()->id,
            'amount' => '360.00', 'party_id' => $pi->client_id, 'proforma_invoice_id' => $pi->id], $manager);

        $this->cancel($pi, $manager)->assertSessionHasErrors(['pi' => 'Money has been received on this PI (USD 360.00). Refund it to the client, or reverse the receipt and record it again on the client without a PI, first.']);
        $this->assertSame('confirmed', $pi->fresh()->status);

        // The database refuses it too.
        DB::beginTransaction();
        try {
            DB::table('proforma_invoices')->where('id', $pi->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'x']);
            $this->fail('the database cancelled a PI with money received');
        } catch (QueryException $e) {
            $this->assertStringContainsString('has money received', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        app(PaymentService::class)->reverse($receipt, $manager, 'Refunded to the client');
        $this->cancel($pi, $manager)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $pi->fresh()->status);
    }

    public function test_a_pi_with_a_posted_invoice_is_not_cancelled_until_the_invoice_is_reversed(): void
    {
        $manager = $this->manager();
        $this->fxRate('USD', '2026-11-01', '1');
        $pi = $this->confirmedPi();
        $entry = $this->posting()->postClientInvoice($pi, $manager);

        $this->cancel($pi, $manager)->assertSessionHasErrors(['pi' => "This PI's invoice {$entry->number} is posted. Reverse it on the Accounting screen first."]);
        $this->assertSame('confirmed', $pi->fresh()->status);

        $this->posting()->reverse($entry->fresh(), $manager);
        $this->cancel($pi, $manager)->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $pi->fresh()->status);
    }

    public function test_a_confirmed_pi_is_cancelled_by_a_manager_and_its_purchase_orders_first(): void
    {
        $sales = $this->userWith(Roles::SALES);
        $pi = $this->confirmedPi();
        $pi->client->forceFill(['account_manager_id' => $sales->id])->save();
        $this->cancel($pi, $sales)->assertSessionHasErrors(['pi' => 'A confirmed PI is the sales order: a manager cancels it.']);

        $order = SupplierOrder::factory()->create();
        SupplierOrderLine::factory()->create(['supplier_order_id' => $order->id, 'pi_line_id' => $pi->lines()->first()->id]);
        $this->cancel($pi)->assertSessionHasErrors(['pi' => "This PI has purchase orders ({$order->number}). Cancel them first."]);
        $this->assertSame('confirmed', $pi->fresh()->status);
    }

    public function test_a_clean_draft_or_issued_pi_is_still_cancelled(): void
    {
        $sales = $this->userWith(Roles::SALES);
        $draft = ProformaInvoice::factory()->create();
        $issued = ProformaInvoice::factory()->issued()->has(PiLine::factory()->state(['line_no' => 1]), 'lines')->create();
        foreach ([$draft, $issued] as $pi) {
            $pi->client->forceFill(['account_manager_id' => $sales->id])->save();
            $this->cancel($pi, $sales)->assertSessionHasNoErrors();
            $this->assertSame('cancelled', $pi->fresh()->status);
        }
    }
}
