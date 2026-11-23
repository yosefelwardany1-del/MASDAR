<?php

namespace Tests\Feature\ProformaInvoices;

use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\PiLine;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Security\Roles;
use App\Services\Configuration\BusinessSettingsService;
use App\Services\Finance\PaymentService;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use App\Services\Quotations\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Spec §5.2: draft → issued (immutable, I4) → accepted → confirmed, cancel with a reason, and
 * revisions under the same number with exactly one current.
 */
class ProformaInvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function make(string ...$roles): User
    {
        return User::factory()->create(['locale' => 'en'])->assignRole($roles);
    }

    private function service(): ProformaInvoiceService
    {
        return app(ProformaInvoiceService::class);
    }

    /** A draft for $client (one of $by's clients by default) with one line of 10 × 48 at 1.25. */
    private function draftWithLine(User $by, ?Party $client = null): ProformaInvoice
    {
        $this->actingAs($by);
        $client ??= Party::factory()->client()->create(['account_manager_id' => $by->id]);
        $pi = $this->service()->createDraft(['client_id' => $client->id], $by);
        $this->service()->addLine($pi, ['product_id' => Product::factory()->create()->id, 'cartons' => '10', 'unit_price' => '1.25'], $by);

        return $pi->fresh();
    }

    private function assertAudited(string $action, ProformaInvoice $pi): void
    {
        $this->assertTrue(
            AuditLog::where('action', $action)->where('model_type', ProformaInvoice::class)->where('model_id', $pi->id)->exists(),
            "no [{$action}] audit row for PI {$pi->id}",
        );
    }

    public function test_a_draft_is_numbered_with_the_business_deposit_and_terms(): void
    {
        $sales = $this->make(Roles::SALES);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id, 'default_destination' => 'Jebel Ali, Dubai']);

        $this->actingAs($sales)->post(route('proforma-invoices.store'), ['client_id' => $client->id, 'po_number' => 'PO-77'])
            ->assertRedirect();

        $pi = ProformaInvoice::sole();
        $this->assertSame('PI-'.now()->year.'-0001', $pi->pi_number);
        $this->assertSame(1, $pi->revision);
        $this->assertTrue($pi->is_current);
        $this->assertSame('draft', $pi->status);
        $this->assertSame('USD', $pi->currency);
        $this->assertSame('30.00', $pi->deposit_percent);
        $this->assertSame('30% DEPOSIT 70% FINAL PAYMENT BEFORE SHIPPING', $pi->terms_text);
        $this->assertSame('Jebel Ali, Dubai', $pi->shipped_to);
        $this->assertSame($sales->id, $pi->created_by);
        $this->assertAudited('created', $pi);
    }

    public function test_the_clients_deposit_overrides_the_business_default(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $client = Party::factory()->client()->create(['deposit_percent' => 50]);

        $this->actingAs($manager)->post(route('proforma-invoices.store'), ['client_id' => $client->id])->assertRedirect();
        $pi = ProformaInvoice::sole();
        $this->assertSame('50.00', $pi->deposit_percent);
        $this->assertSame('50% DEPOSIT 50% FINAL PAYMENT BEFORE SHIPPING', $pi->terms_text);

        // A deposit set on the PI wins, and the terms follow it.
        $this->get(route('proforma-invoices.edit', $pi))->assertOk();
        $this->put(route('proforma-invoices.update', $pi), ['client_id' => $client->id, 'deposit_percent' => '40'])->assertRedirect();
        $this->assertSame('40% DEPOSIT 60% FINAL PAYMENT BEFORE SHIPPING', $pi->fresh()->terms_text);
        $this->assertAudited('updated', $pi);
    }

    public function test_a_line_with_a_new_product_creates_the_product_and_the_totals_are_right(): void
    {
        Storage::fake(config('attachments.disk', 'local'));
        $manager = $this->make(Roles::MANAGER);
        $this->actingAs($manager)->post(route('proforma-invoices.store'), ['client_id' => Party::factory()->client()->create()->id]);
        $pi = ProformaInvoice::sole();

        $this->post(route('proforma-invoices.lines.store', $pi), [
            'form' => 'add', 'product' => 'new', 'description' => "Ceramic mug\n350 ml, white", 'unit' => 'pcs',
            'cartons' => '12', 'qty_per_carton' => '24', 'unit_price' => '0.875', 'cbm_per_carton' => '0.042', 'kg_per_carton' => '8.2',
            'photo' => UploadedFile::fake()->image('mug.jpg'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product = Product::sole();
        $this->assertSame('Ceramic mug', $product->name);
        $this->assertSame('24.0000', $product->qty_per_carton);
        $this->assertNotNull($product->photo);

        $line = PiLine::sole();
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame(1, $line->line_no);
        $this->assertSame('288.0000', $line->total_qty);
        $this->assertSame('252.0000', $line->amount);
        $this->assertSame('0.5040', $line->total_cbm);
        $this->assertSame('98.4000', $line->total_kg);
        $this->assertSame('252.0000', $pi->lineTotals()->amount);
        $this->assertAudited('line_added', $pi);

        $this->get(route('proforma-invoices.show', $pi))->assertOk()->assertSee('$252.00')->assertSee('$0.875');
    }

    public function test_a_line_takes_its_defaults_from_an_existing_product_and_lines_renumber(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $this->actingAs($manager);
        $pi = $this->service()->createDraft(['client_id' => Party::factory()->client()->create()->id], $manager);
        $product = Product::factory()->create(['name' => 'Glass vase', 'unit' => 'set', 'qty_per_carton' => 6, 'cbm_per_carton' => '0.1200', 'kg_per_carton' => '9']);

        $this->post(route('proforma-invoices.lines.store', $pi), ['form' => 'add', 'product' => (string) $product->id, 'cartons' => '5', 'unit_price' => '3'])
            ->assertSessionHasNoErrors();
        $line = PiLine::sole();
        $this->assertSame('Glass vase', $line->description);
        $this->assertSame('set', $line->unit);
        $this->assertSame('6.0000', $line->qty_per_carton);
        $this->assertSame('0.1200', $line->cbm_per_carton);
        $this->assertSame('90.0000', $line->amount);

        foreach (['2', '3'] as $cartons) {
            $this->service()->addLine($pi, ['product_id' => $product->id, 'cartons' => $cartons, 'unit_price' => '1'], $manager);
        }
        $second = PiLine::where('line_no', 2)->sole();
        $this->delete(route('proforma-invoices.lines.destroy', [$pi, $second]))->assertRedirect();

        $this->assertSame([1 => '5.0000', 2 => '3.0000'], PiLine::orderBy('line_no')->pluck('cartons', 'line_no')->all());
        $this->assertAudited('line_removed', $pi);

        $this->put(route('proforma-invoices.lines.update', [$pi, $line]), [
            'form' => 'line-'.$line->id, 'unit' => 'set', 'cartons' => '7', 'qty_per_carton' => '6', 'unit_price' => '3',
        ])->assertSessionHasNoErrors();
        $this->assertSame('126.0000', $line->fresh()->amount);
        $this->assertAudited('line_updated', $pi);
    }

    public function test_a_refusal_by_the_database_becomes_a_validation_error(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $pi = $this->draftWithLine($manager);

        try {
            $this->service()->addLine($pi, ['product_id' => Product::factory()->create()->id, 'cartons' => '0', 'unit_price' => '1'], $manager);
            $this->fail('a line with no cartons was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pi', $e->errors());
        }
        $this->assertSame(1, PiLine::count());
    }

    public function test_issue_is_refused_without_lines(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $this->actingAs($manager);
        $pi = $this->service()->createDraft(['client_id' => Party::factory()->client()->create()->id], $manager);

        $this->post(route('proforma-invoices.issue', $pi))->assertSessionHasErrors('pi');
        $this->assertSame('draft', $pi->fresh()->status);
    }

    public function test_when_approval_is_required_sales_cannot_issue_and_a_manager_can(): void
    {
        $sales = $this->make(Roles::SALES);
        $manager = $this->make(Roles::MANAGER);
        $pi = $this->draftWithLine($sales);

        $this->actingAs($sales)->post(route('proforma-invoices.issue', $pi))->assertForbidden();
        $this->assertSame('draft', $pi->fresh()->status);
        $this->get(route('proforma-invoices.show', $pi))->assertOk()->assertSee('needs a manager');

        $this->actingAs($manager)->post(route('proforma-invoices.issue', $pi))->assertRedirect()->assertSessionHasNoErrors();
        $pi->refresh();
        $this->assertSame('issued', $pi->status);
        $this->assertNotNull($pi->issued_at);
        $this->assertSame($manager->id, $pi->issued_by);
        $this->assertAudited('issued', $pi);
    }

    public function test_without_approval_sales_issues_their_own_pi(): void
    {
        $this->actingAs($this->make(Roles::MANAGER));
        app(BusinessSettingsService::class)->update(['pi_issue_requires_approval' => false]);
        $sales = $this->make(Roles::SALES);
        $pi = $this->draftWithLine($sales);

        $this->actingAs($sales)->post(route('proforma-invoices.issue', $pi))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('issued', $pi->fresh()->status);
    }

    public function test_an_issued_pi_and_its_lines_cannot_change(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $pi = $this->draftWithLine($manager);
        $this->service()->issue($pi, $manager);
        $line = PiLine::sole();

        $this->put(route('proforma-invoices.lines.update', [$pi, $line]), [
            'form' => 'line-'.$line->id, 'unit' => 'pcs', 'cartons' => '99', 'qty_per_carton' => '48', 'unit_price' => '1.25',
        ])->assertRedirect()->assertSessionHasErrors(['pi' => 'This PI has been issued and can no longer change. Make a new revision to change it.']);
        $this->assertSame('10.0000', $line->fresh()->cartons);

        $this->post(route('proforma-invoices.lines.store', $pi), ['form' => 'add', 'product' => (string) $line->product_id, 'cartons' => '1', 'unit_price' => '1'])
            ->assertSessionHasErrors('pi');
        $this->delete(route('proforma-invoices.lines.destroy', [$pi, $line]))->assertSessionHasErrors('pi');
        $this->put(route('proforma-invoices.update', $pi), ['client_id' => $pi->client_id, 'po_number' => 'CHANGED'])->assertSessionHasErrors('pi');
        $this->assertSame(1, PiLine::count());
        $this->assertNull($pi->fresh()->po_number);
        $this->get(route('proforma-invoices.edit', $pi))->assertRedirect(route('proforma-invoices.show', $pi));
    }

    /** B27: whole cartons on PI and quotation lines, unless the business allows part cartons. */
    public function test_part_cartons_are_refused_unless_allowed(): void
    {
        app()->setLocale('en');
        $manager = $this->make(Roles::MANAGER);
        $pi = $this->draftWithLine($manager);
        $product = Product::factory()->create();
        $message = 'Cartons are whole: enter 2 or 3, not 2.5.';

        $this->assertThrows(fn () => $this->service()->addLine($pi, ['product_id' => $product->id, 'cartons' => '2.5', 'unit_price' => '1'], $manager), ValidationException::class, $message);
        $this->assertThrows(fn () => $this->service()->updateLine($pi->lines()->first(), ['cartons' => '2.5'], $manager), ValidationException::class, $message);
        $quotation = app(QuotationService::class)->create(['client_id' => $pi->client_id, 'currency' => 'USD'], $manager);
        $this->assertThrows(fn () => app(QuotationService::class)->addLine($quotation, ['description' => 'Jar', 'unit' => 'pcs', 'cartons' => '2.5', 'qty_per_carton' => '10', 'unit_price' => '1'], $manager),
            ValidationException::class, $message);
        $this->assertSame(1, $pi->lines()->count());

        BusinessSetting::current()->fill(['part_cartons_allowed' => true])->save();
        $this->service()->addLine($pi, ['product_id' => $product->id, 'cartons' => '2.5', 'unit_price' => '1'], $manager);
        $this->assertSame('2.5000', (string) $pi->lines()->reorder('line_no', 'desc')->first()->cartons);
    }

    public function test_accept_confirm_and_cancel(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $pi = $this->draftWithLine($manager);

        $this->post(route('proforma-invoices.accept', $pi))->assertSessionHasErrors('pi');
        $this->service()->issue($pi, $manager);

        $this->post(route('proforma-invoices.accept', $pi))->assertSessionHasNoErrors();
        $this->assertSame('accepted', $pi->fresh()->status);
        $this->assertNotNull($pi->fresh()->accepted_at);

        // Confirmed once the client's deposit has arrived (B03).
        $this->post(route('proforma-invoices.confirm', $pi))->assertSessionHasErrors('pi');
        $deposit = app(PaymentService::class)->record(['kind' => 'client_receipt', 'paid_on' => now()->toDateString(),
            'money_account_id' => MoneyAccount::factory()->create()->id, 'amount' => $this->service()->depositStatus($pi->fresh())['required'],
            'party_id' => $pi->client_id, 'proforma_invoice_id' => $pi->id], $manager);
        $this->post(route('proforma-invoices.confirm', $pi))->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $pi->fresh()->status);
        $this->assertNotNull($pi->fresh()->confirmed_at);

        $this->post(route('proforma-invoices.cancel', $pi), ['cancel_reason' => ''])->assertSessionHasErrors('cancel_reason');
        $this->assertSame('confirmed', $pi->fresh()->status);

        // With the deposit in, it is refunded before the PI is cancelled (B02).
        $this->post(route('proforma-invoices.cancel', $pi), ['cancel_reason' => 'Client withdrew the order'])->assertSessionHasErrors('pi');
        app(PaymentService::class)->reverse($deposit, $manager, 'Refunded to the client');
        $this->post(route('proforma-invoices.cancel', $pi), ['cancel_reason' => 'Client withdrew the order'])->assertSessionHasNoErrors();
        $pi->refresh();
        $this->assertSame('cancelled', $pi->status);
        $this->assertSame('Client withdrew the order', $pi->cancel_reason);
        $this->assertNotNull($pi->cancelled_at);
        $this->post(route('proforma-invoices.cancel', $pi), ['cancel_reason' => 'again'])->assertSessionHasErrors('pi');

        foreach (['accepted', 'confirmed', 'cancelled'] as $action) {
            $this->assertAudited($action, $pi);
        }

        // A draft is cancelled too, never deleted.
        $draft = $this->draftWithLine($manager);
        $this->post(route('proforma-invoices.cancel', $draft), ['cancel_reason' => 'Duplicate'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $draft->fresh()->status);
    }

    public function test_a_revision_is_a_draft_and_issuing_it_supersedes_the_old_one(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $first = $this->draftWithLine($manager);
        $this->post(route('proforma-invoices.revise', $first))->assertSessionHasErrors('pi'); // a draft is changed, not revised
        $this->service()->issue($first, $manager);
        $this->service()->accept($first, $manager);

        $this->post(route('proforma-invoices.revise', $first))->assertRedirect()->assertSessionHasNoErrors();
        $second = ProformaInvoice::where('pi_number', $first->pi_number)->where('revision', 2)->sole();
        $this->assertSame('draft', $second->status);
        $this->assertFalse($second->is_current);
        $this->assertSame($first->client_id, $second->client_id);
        $this->assertSame($first->terms_text, $second->terms_text);
        $copied = $second->lines()->sole();
        $original = $first->lines()->sole();
        $this->assertSame($original->id, $copied->source_line_id);
        $this->assertSame($original->amount, $copied->amount);
        $this->assertAudited('revision_created', $second);

        // One draft revision at a time.
        $this->post(route('proforma-invoices.revise', $first))->assertSessionHasErrors('pi');

        // The draft revision changes; the accepted revision stays current and untouched meanwhile.
        $this->put(route('proforma-invoices.lines.update', [$second, $copied]), [
            'form' => 'line-'.$copied->id, 'unit' => 'pcs', 'cartons' => '20', 'qty_per_carton' => '48', 'unit_price' => '1.25',
        ])->assertSessionHasNoErrors();
        $this->assertSame('1200.0000', $copied->fresh()->amount);
        $this->assertSame('600.0000', $original->fresh()->amount);
        $this->assertTrue($first->fresh()->is_current);

        $this->post(route('proforma-invoices.issue', $second))->assertSessionHasNoErrors();
        $first->refresh();
        $second->refresh();
        $this->assertSame('superseded', $first->status);
        $this->assertFalse($first->is_current);
        $this->assertSame('issued', $second->status);
        $this->assertTrue($second->is_current);
        $this->assertSame(1, ProformaInvoice::where('pi_number', $first->pi_number)->where('is_current', true)->count());
        $this->assertAudited('superseded', $first);

        $this->get(route('proforma-invoices.index'))->assertOk()->assertSee($first->pi_number.' rev 2')->assertSee('$1,200.00');
        $this->get(route('proforma-invoices.show', $first))->assertOk()->assertSee('This is not the current revision.');
    }

    public function test_issuing_a_revision_of_a_pi_cancelled_meanwhile_keeps_it_cancelled(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $first = $this->draftWithLine($manager);
        $this->service()->issue($first, $manager);
        $second = $this->service()->createRevision($first, $manager);
        $this->service()->cancel($first, $manager, 'Replaced by revision 2');

        $this->service()->issue($second, $manager);
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->fresh()->is_current);
    }
}
