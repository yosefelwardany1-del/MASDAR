<?php

namespace Tests\Feature\Finance;

use App\Models\Attachment;
use App\Models\Container;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Security\Roles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** The Payments screen: permissions, the forms, approval and reversal through HTTP. */
class PaymentScreensTest extends FinanceTestCase
{
    public function test_staff_are_refused_every_money_page(): void
    {
        $staff = $this->user(Roles::STAFF);
        $payment = $this->receipt($this->usd(), '10');

        foreach ([
            route('payments.index'), route('payments.create'), route('payments.show', $payment), route('payments.transfer.create'),
            route('money-accounts.index'), route('money-accounts.create'), route('money-accounts.show', $payment->money_account_id),
            route('fx-rates.index'), route('fx-rates.create'),
        ] as $url) {
            $this->actingAs($staff)->get($url)->assertForbidden();
        }
        $this->actingAs($staff)->post(route('payments.store'), [])->assertForbidden();
        $this->actingAs($this->user(Roles::SYSTEM_ADMIN))->get(route('payments.index'))->assertForbidden();
    }

    public function test_the_accountant_records_a_receipt_with_evidence_and_the_pages_render(): void
    {
        Storage::fake('local');
        $accountant = $this->user(Roles::ACCOUNTANT);
        $usd = $this->usd();
        $pi = ProformaInvoice::factory()->worth('5000')->issued()->create();

        $this->actingAs($accountant)->get(route('payments.create', ['kind' => 'client_receipt', 'party' => $pi->client_id, 'pi' => $pi->id]))
            ->assertOk()
            ->assertSee('value="'.$pi->id.'" data-party="'.$pi->client_id.'" selected', false)
            ->assertSee($pi->client->name);

        $response = $this->actingAs($accountant)->post(route('payments.store'), [
            'kind' => 'client_receipt', 'paid_on' => now()->toDateString(), 'money_account_id' => $usd->id, 'amount' => '1200.50',
            'party_id' => $pi->client_id, 'proforma_invoice_id' => $pi->id, 'reference' => 'HSBC-001',
            'files' => [UploadedFile::fake()->create('slip.pdf', 50, 'application/pdf')],
        ]);
        $payment = Payment::firstOrFail();
        $response->assertRedirect(route('payments.show', $payment));
        $this->assertSame('1200.5000', $payment->amount);
        $this->assertSame(1, Attachment::where('attachable_type', Payment::class)->where('attachable_id', $payment->id)->count());

        $this->actingAs($accountant)->get(route('payments.show', $payment))->assertOk()
            ->assertSee($payment->number)->assertSee('slip.pdf')->assertSee($pi->reference());
        $this->actingAs($accountant)->get(route('payments.index'))->assertOk()->assertSee($payment->number)->assertSee('1,200.50');
        $this->actingAs($this->user(Roles::MANAGER))->get(route('payments.index', ['kind' => 'client_receipt', 'from' => now()->toDateString()]))->assertOk()->assertSee($payment->number);
    }

    public function test_each_kind_has_its_form_and_other_modules_can_preselect_it(): void
    {
        $accountant = $this->user(Roles::ACCOUNTANT);
        $order = SupplierOrder::factory()->create();
        $container = Container::factory()->create();
        $shipper = Party::factory()->shippingCompany()->create();

        foreach (['client_receipt', 'supplier_payment', 'shipping_payment', 'expense', 'other_in', 'other_out'] as $kind) {
            $this->actingAs($accountant)->get(route('payments.create', ['kind' => $kind]))->assertOk()->assertSee('name="kind" value="'.$kind.'"', false);
        }
        $this->actingAs($accountant)->get(route('payments.create', ['kind' => 'supplier_payment', 'party' => $order->supplier_id, 'order' => $order->id]))
            ->assertOk()->assertSee('value="'.$order->id.'" data-party="'.$order->supplier_id.'" selected', false);
        $this->actingAs($accountant)->get(route('payments.create', ['kind' => 'shipping_payment', 'party' => $shipper->id, 'container' => $container->id]))
            ->assertOk()->assertSee('value="'.$container->id.'" selected', false)->assertSee('value="'.$shipper->id.'" selected', false);
        $this->actingAs($accountant)->get(route('payments.transfer.create'))->assertOk();
    }

    public function test_only_the_manager_approves_and_the_accountant_reverses(): void
    {
        $accountant = $this->user(Roles::ACCOUNTANT);
        $manager = $this->user(Roles::MANAGER);
        $out = $this->supplierPayment($this->usd(), '800');

        $this->actingAs($accountant)->get(route('payments.show', $out))->assertOk()->assertDontSee(route('payments.approve', $out));
        $this->actingAs($accountant)->post(route('payments.approve', $out))->assertForbidden();
        $this->assertSame(0, PaymentApproval::count());

        $this->actingAs($manager)->get(route('payments.show', $out))->assertOk()->assertSee(route('payments.approve', $out));
        $this->actingAs($manager)->post(route('payments.approve', $out), ['approval_note' => 'checked the invoice'])->assertRedirect(route('payments.show', $out));
        $this->assertSame(1, PaymentApproval::count());
        $this->actingAs($manager)->from(route('payments.show', $out))->post(route('payments.approve', $out))->assertSessionHasErrors('payment');
        $this->assertSame(1, PaymentApproval::count());

        $this->actingAs($accountant)->from(route('payments.show', $out))->post(route('payments.reverse', $out), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($accountant)->post(route('payments.reverse', $out), ['reason' => 'paid twice'])->assertRedirect(route('payments.show', $out));
        $reversal = Payment::whereNotNull('reverses_payment_id')->firstOrFail();
        $this->assertSame('paid twice', $reversal->note);
        $this->actingAs($accountant)->get(route('payments.show', $reversal))->assertOk()->assertSee($out->number);
        $this->actingAs($this->user(Roles::SALES))->post(route('payments.reverse', $out), ['reason' => 'x'])->assertForbidden();
    }

    public function test_a_transfer_is_recorded_through_the_form(): void
    {
        $this->setRate('CNY', now()->toDateString(), '0.14');
        $usd = $this->usd();
        $cny = $this->cny();

        $this->actingAs($this->user(Roles::ACCOUNTANT))->post(route('payments.transfer.store'), [
            'from_account_id' => $usd->id, 'to_account_id' => $cny->id, 'paid_on' => now()->toDateString(),
            'amount' => '500', 'in_amount' => '3550',
        ])->assertRedirect();

        $this->assertSame(['transfer_out', 'transfer_in'], Payment::orderBy('id')->pluck('kind')->all());
        $this->assertSame(1, Payment::distinct()->count('transfer_group'));
        $this->assertSame('0.140000', Payment::where('kind', 'transfer_in')->value('rate'));

        $this->actingAs($this->user(Roles::MANAGER))->get(route('payments.show', Payment::where('kind', 'transfer_in')->first()))->assertOk()->assertSee(Payment::where('kind', 'transfer_out')->value('number'));
    }

    public function test_the_awaiting_filter_lists_what_a_manager_must_approve(): void
    {
        $usd = $this->usd();
        $waiting = $this->supplierPayment($usd, '10');
        $approved = $this->supplierPayment($usd, '20');
        $this->payments()->approve($approved, $this->user(Roles::MANAGER));
        $receipt = $this->receipt($usd, '30');

        $this->actingAs($this->user(Roles::MANAGER))->get(route('payments.index', ['awaiting' => 1]))->assertOk()
            ->assertSee($waiting->number)->assertDontSee($approved->number)->assertDontSee($receipt->number);
    }
}
