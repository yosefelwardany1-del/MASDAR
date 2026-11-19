<?php

namespace Tests\Feature\Accounting;

use App\Models\MoneyAccount;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\LedgerReports;
use App\Services\Finance\PaymentService;
use App\Services\ProformaInvoices\PiAmounts;
use App\Services\Quotations\QuotationService;
use App\Services\ServiceInvoices\ServiceInvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B09: money is to the cent — lines, totals, deposits, payments and journal lines; prices keep 4 decimals. */
class MoneyToTheCentTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    public function test_a_pi_line_its_total_and_its_deposit_are_to_the_cent(): void
    {
        $pi = ProformaInvoice::factory()->create(['deposit_percent' => 40]);
        PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'line_no' => 1, 'cartons' => 1, 'qty_per_carton' => 100, 'unit_price' => '4.31355']);
        $line = $pi->lines()->sole();
        $this->assertSame(['4.3136', '431.3600'], [(string) $line->unit_price, (string) $line->amount], 'price to 4 decimals, amount to the cent');
        $this->assertSame('431.3600', $pi->lineTotals()->amount);
        $this->assertSame('172.5400', PiAmounts::depositRequired($pi->lineTotals()->amount, '40'));

        $this->actingAs($this->manager())->get(route('proforma-invoices.print', $pi))->assertOk()->assertSee('431.36')->assertDontSee('431.355');
    }

    public function test_a_service_invoice_shows_the_same_cents_on_screen_and_in_print(): void
    {
        $manager = $this->manager();
        $pi = $this->confirmedPi();
        $invoice = app(ServiceInvoiceService::class)->createDraft(['proforma_invoice_id' => $pi->id, 'lines' => [
            ['category' => 'inspection', 'description' => 'Inspection', 'unit_price' => '150.555'],
            ['category' => 'commission', 'description' => 'Commission', 'unit_price' => '99'],
        ]], $manager);
        $this->assertSame(['150.5600', '249.5600'], [(string) $invoice->lines()->orderBy('line_no')->first()->amount, (string) $invoice->lines()->sum('amount')]);
        $this->actingAs($manager)->get(route('service-invoices.show', $invoice))->assertSee('249.56');
        // The price keeps its 4 decimals (150.555); the amount and the total are to the cent.
        $this->actingAs($manager)->get(route('service-invoices.print', $invoice))->assertSee('150.56')->assertSee('249.56')->assertDontSee('249.555');
    }

    public function test_a_quotation_line_and_its_print_are_to_the_cent(): void
    {
        $manager = $this->manager();
        $pi = $this->confirmedPi();
        $quotation = app(QuotationService::class)->create(['client_id' => $pi->client_id, 'currency' => 'USD'], $manager);
        app(QuotationService::class)->addLine($quotation, ['description' => 'Jar', 'unit' => 'pcs', 'cartons' => '1', 'qty_per_carton' => '10', 'unit_price' => '2.6355'], $manager);
        $this->assertSame('26.36', $quotation->fresh()->lines->sole()->amount());
        $this->actingAs($manager)->get(route('quotations.print', $quotation))->assertSee('26.36')->assertDontSee('26.355');
    }

    public function test_payments_and_journal_lines_are_to_the_cent_and_the_trial_balance_adds_up(): void
    {
        $manager = $this->manager();
        $cny = MoneyAccount::factory()->rmb()->create();
        $this->fxRate('CNY', now()->subDay()->toDateString(), '0.138765');
        $payments = app(PaymentService::class);

        $this->assertThrows(fn () => $payments->record(['kind' => 'other_in', 'paid_on' => now()->toDateString(), 'money_account_id' => $cny->id, 'amount' => '1000.055',
            'counter_account_id' => $this->account('4201')->id], $manager), ValidationException::class);
        $payment = $payments->record(['kind' => 'other_in', 'paid_on' => now()->toDateString(), 'money_account_id' => $cny->id, 'amount' => '1000.05',
            'counter_account_id' => $this->account('4201')->id], $manager);
        $this->assertSame('138.7700', (string) $payment->fresh()->book_amount, '1000.05 × 0.138765 = 138.7719…');

        $entry = $this->posting()->postPayment($payment->fresh(), $manager);
        foreach ($entry->lines as $line) {
            $this->assertSame((string) $line->debit, bcadd((string) $line->debit, '0', 2).'00');
            $this->assertSame((string) $line->credit, bcadd((string) $line->credit, '0', 2).'00');
        }

        // The database keeps journal lines to the cent (a draft, which still takes lines).
        $draft = $this->posting()->createDraft(['entry_date' => now()->toDateString(), 'description' => 'Test', 'lines' => [
            ['account_id' => $this->account('1101')->id, 'debit' => '1'], ['account_id' => $this->account('4201')->id, 'credit' => '1'],
        ]], $manager);
        $this->assertThrows(fn () => $this->posting()->createDraft(['entry_date' => now()->toDateString(), 'description' => 'Test', 'lines' => [
            ['account_id' => $this->account('1101')->id, 'debit' => '1.005'], ['account_id' => $this->account('4201')->id, 'credit' => '1.005'],
        ]], $manager), AccountingException::class, 'Line 1: an amount has at most 2 decimals.');
        DB::beginTransaction();
        try {
            DB::table('journal_lines')->insert(['journal_entry_id' => $draft->id, 'account_id' => $this->account('1101')->id, 'debit' => '0.005', 'credit' => 0,
                'currency' => 'USD', 'amount_currency' => '0.005', 'rate' => 1]);
            $this->fail('a journal line below the cent was written');
        } catch (QueryException $e) {
            $this->assertStringContainsString('journal_lines_cents_check', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        $report = app(LedgerReports::class)->trialBalance(now()->startOfYear()->toDateString(), now()->toDateString());
        $shown = array_reduce($report['rows'], fn ($c, $r) => bcadd($c, $r['credit'], 4), '0');
        $this->assertSame(bcadd($shown, '0', 4), bcadd($report['totals']['credit'], '0', 4), 'the total is the sum of the cents shown');
        $this->assertSame($report['totals']['debit'], $report['totals']['credit']);
    }
}
