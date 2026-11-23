<?php

namespace Tests\Feature\Platform;

use App\Models\BankStatementLine;
use App\Models\FxRate;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Finance\BankReconciliationService;
use App\Services\ServiceInvoices\ServiceInvoiceService;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B10: a person never sees the database's words — table, constraint or SQL — only plain ones. */
class PlainRefusalsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $this->manager = User::where('email', 'manager@example.test')->sole();
        $this->manager->forceFill(['locale' => 'en'])->save();
    }

    private function invoice()
    {
        $pi = ProformaInvoice::query()->current()->whereHas('client', fn ($q) => $q->where('name', 'Gulf Home Trading LLC'))->sole();

        return app(ServiceInvoiceService::class)->createDraft(['proforma_invoice_id' => $pi->id, 'lines' => [
            ['category' => 'inspection', 'description' => 'Inspection', 'unit_price' => '150'],
        ]], $this->manager);
    }

    public function test_a_due_date_before_the_invoice_date_is_said_plainly(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->manager)->post(route('service-invoices.issue', $invoice), ['issued_on' => now()->toDateString(), 'due_on' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors(['due_on' => 'The due date can\'t be before the invoice date.']);
        $this->assertThrows(fn () => app(ServiceInvoiceService::class)->issue($invoice, $this->manager, now()->toDateString(), now()->subDay()->toDateString()),
            ValidationException::class, 'The due date can\'t be before the invoice date.');
    }

    public function test_voiding_a_posted_invoice_says_to_reverse_it_first(): void
    {
        $invoice = app(ServiceInvoiceService::class)->issue($this->invoice(), $this->manager);
        app(PostingService::class)->postServiceInvoice($invoice, $this->manager);
        $this->actingAs($this->manager)->post(route('service-invoices.void', $invoice), ['void_reason' => 'Wrong client'])
            ->assertSessionHasErrors(['invoice' => 'This invoice is posted. Reverse its journal entry on the Accounting screen first.']);
    }

    public function test_matching_a_payment_of_another_amount_says_so(): void
    {
        $usd = MoneyAccount::where('name', 'HSBC Hong Kong — USD')->sole();
        $statement = app(BankReconciliationService::class)->import($usd, [[now()->toDateString(), 'TT', '999.99']],
            ['date' => 0, 'description' => 1, 'amount' => 2, 'date_format' => 'Y-m-d'], $this->manager);
        $line = BankStatementLine::where('bank_statement_id', $statement->id)->sole();
        $receipt = Payment::where('kind', 'client_receipt')->first();
        $this->actingAs($this->manager)->post(route('bank-reconciliation.match', [$statement, $line]), ['payment_id' => $receipt->id])
            ->assertSessionHasErrors(['payment_id' => 'This payment\'s amount is different from the statement line.']);
    }

    public function test_a_refusal_no_writer_caught_comes_back_in_plain_words_without_table_names(): void
    {
        Route::middleware('web')->post('/_test/refusal', function () {
            DB::transaction(fn () => DB::table('fx_rates')->insert(['currency' => 'CNY', 'rate_date' => now()->toDateString(), 'rate' => '-1', 'created_by' => 1]));
        });
        $response = $this->actingAs($this->manager)->from('/dashboard')->post('/_test/refusal');
        $response->assertRedirect('/dashboard');
        $message = session('error');
        $this->assertSame('This change was refused because it breaks a rule of the system, so nothing was saved. Check what you entered, or ask your administrator.', $message);
        $this->assertStringNotContainsString('fx_rates', $message);
        $this->assertSame(0, FxRate::where('rate', '-1')->count());
    }
}
