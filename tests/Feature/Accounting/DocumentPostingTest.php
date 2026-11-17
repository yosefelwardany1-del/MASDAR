<?php

namespace Tests\Feature\Accounting;

use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrderLine;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PostingQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Client invoices, supplier bills and shipping bills post by rule, each source once. */
class DocumentPostingTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    public function test_a_confirmed_pi_is_invoiced_for_the_sum_of_its_lines(): void
    {
        $accountant = $this->accountant();
        $pi = $this->confirmedPi();
        $this->assertTrue(app(PostingQueue::class)->invoices()->contains('id', $pi->id));

        $this->actingAs($accountant)->post(route('accounting.to-post.invoice', $pi))->assertSessionHas('success');

        $entry = JournalEntry::query()->where('source_type', 'proforma_invoice')->where('source_id', $pi->id)->firstOrFail();
        $this->assertSame('client_invoice', $entry->event);
        $this->assertSame('2026-11-05', $entry->entry_date->toDateString());
        [$debit, $credit] = $entry->lines()->with('account')->get()->all();
        $this->assertSame(['1401', '1200.0000', $pi->client_id], [$debit->account->code, $debit->debit, $debit->party_id]);
        $this->assertSame(['4101', '1200.0000', null], [$credit->account->code, $credit->credit, $credit->party_id]);
        $this->assertSame(['USD', '1200.0000', '1.000000'], [$debit->currency, $debit->amount_currency, $debit->rate]);
        $this->assertFalse(app(PostingQueue::class)->invoices()->contains('id', $pi->id));
    }

    public function test_a_pi_in_another_currency_takes_the_rate_on_its_invoice_date_or_fails_loudly(): void
    {
        $accountant = $this->accountant();
        $pi = $this->confirmedPi(['currency' => 'EUR']);

        try {
            $this->posting()->postClientInvoice($pi, $accountant);
            $this->fail('posted without a rate');
        } catch (AccountingException $e) {
            $this->assertStringContainsString('EUR', $e->getMessage());
        }

        $this->fxRate('EUR', '2026-11-01', '1.080000');
        $this->fxRate('EUR', '2026-11-20', '9.000000'); // after the invoice date: not used
        $entry = $this->posting()->postClientInvoice($pi, $accountant);

        $line = $entry->lines()->first();
        $this->assertSame(['EUR', '1200.0000', '1.080000', '1296.0000'], [$line->currency, $line->amount_currency, $line->rate, $line->debit]);
    }

    public function test_only_a_confirmed_current_pi_is_invoiced_and_only_once(): void
    {
        $accountant = $this->accountant();
        $draft = ProformaInvoice::factory()->create();
        $this->assertFalse(app(PostingQueue::class)->invoices()->contains('id', $draft->id));
        $this->expectRefusal(fn () => $this->posting()->postClientInvoice($draft, $accountant), 'confirmed');

        $pi = $this->confirmedPi();
        $this->posting()->postClientInvoice($pi, $accountant);
        $this->expectRefusal(fn () => $this->posting()->postClientInvoice($pi, $accountant), 'already invoiced');
        $this->assertSame(1, JournalEntry::query()->where('event', 'client_invoice')->count());
    }

    public function test_a_fully_received_supplier_order_is_billed_for_the_sum_of_its_lines(): void
    {
        $accountant = $this->accountant();
        $this->fxRate('CNY', '2026-11-01', '0.140000');
        $order = $this->supplierOrder(lines: 2, received: 10);
        $this->assertTrue(app(PostingQueue::class)->bills()->contains('id', $order->id));

        $this->actingAs($accountant)->post(route('accounting.to-post.bill', $order))->assertSessionHas('success');

        $entry = JournalEntry::query()->where('source_type', 'supplier_order')->where('source_id', $order->id)->firstOrFail();
        $this->assertSame(['supplier_bill', '2026-11-12'], [$entry->event, $entry->entry_date->toDateString()]);
        [$debit, $credit] = $entry->lines()->with('account')->get()->all();
        // 2 lines × 10 × 48 × 6.50 = 6,240 CNY × 0.14 = 873.60
        $this->assertSame(['5101', '873.6000', null], [$debit->account->code, $debit->debit, $debit->party_id]);
        $this->assertSame(['2201', '873.6000', $order->supplier_id], [$credit->account->code, $credit->credit, $credit->party_id]);
        $this->assertSame(['CNY', '6240.0000', '0.140000'], [$credit->currency, $credit->amount_currency, $credit->rate]);

        $this->expectRefusal(fn () => $this->posting()->postSupplierBill($order, $accountant), 'already billed');
        $this->assertFalse(app(PostingQueue::class)->bills()->contains('id', $order->id));
    }

    public function test_a_partly_received_order_waits(): void
    {
        $accountant = $this->accountant();
        $this->fxRate('CNY', '2026-11-01', '0.140000');
        $order = $this->supplierOrder(lines: 1, received: 6);

        $this->assertFalse(app(PostingQueue::class)->bills()->contains('id', $order->id));
        $this->expectRefusal(fn () => $this->posting()->postSupplierBill($order, $accountant), 'not fully received');

        GoodsReceipt::create([
            'supplier_order_line_id' => SupplierOrderLine::query()->where('supplier_order_id', $order->id)->value('id'),
            'received_on' => '2026-11-14', 'cartons' => 4, 'inspection_result' => 'pass', 'user_id' => $order->created_by,
        ]);
        $this->assertTrue(app(PostingQueue::class)->bills()->contains('id', $order->id));
        $this->assertSame('2026-11-14', $this->posting()->postSupplierBill($order, $accountant)->entry_date->toDateString());
    }

    public function test_a_shipping_bill_posts_to_the_company_and_a_container_takes_one(): void
    {
        $accountant = $this->accountant();
        $company = $this->shippingCompany();
        $container = Container::factory()->create();

        $this->actingAs($accountant)->post(route('accounting.to-post.shipping-bill'), [
            'shipping_company_id' => $company->id, 'container_id' => $container->id,
            'amount' => '2450.50', 'currency' => 'USD', 'bill_date' => '2026-11-13', 'description' => 'Freight NGB–JEA',
        ])->assertSessionHas('success');

        $entry = JournalEntry::query()->where('event', 'shipping_bill')->firstOrFail();
        $this->assertSame(['container', $container->id], [$entry->source_type, $entry->source_id]);
        [$debit, $credit] = $entry->lines()->with('account')->get()->all();
        $this->assertSame(['5201', '2450.5000'], [$debit->account->code, $debit->debit]);
        $this->assertSame(['2301', '2450.5000', $company->id], [$credit->account->code, $credit->credit, $credit->party_id]);

        $this->actingAs($accountant)->post(route('accounting.to-post.shipping-bill'), [
            'shipping_company_id' => $company->id, 'container_id' => $container->id,
            'amount' => '100', 'currency' => 'USD', 'bill_date' => '2026-11-13',
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'already has a shipping bill'));

        // Without a container any number of bills post.
        $this->posting()->postShippingBill($company, null, '120', 'CNY', '2026-11-13', 'Trucking', $accountant, '0.14');
        $this->assertSame(2, JournalEntry::query()->where('event', 'shipping_bill')->count());
        $this->assertSame('-2467.3000', $this->rawBalance('2301'));
    }

    public function test_post_all_reports_each_refusal_and_posts_the_rest(): void
    {
        $accountant = $this->accountant();
        $ok = $this->confirmedPi();
        $noRate = $this->confirmedPi(['currency' => 'EUR']);

        $this->actingAs($accountant)->post(route('accounting.to-post.invoices'))
            ->assertSessionHas('success')
            ->assertSessionHas('error', fn ($m) => str_contains($m, $noRate->pi_number) && str_contains($m, 'EUR'));
        $this->assertNotNull(app(Ledger::class)->postingOf('proforma_invoice', $ok->id));
        $this->assertNull(app(Ledger::class)->postingOf('proforma_invoice', $noRate->id));
    }

    private function expectRefusal(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('the posting was not refused');
        } catch (AccountingException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
