<?php

namespace Tests\Feature\ProformaInvoices;

use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PiLine;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Security\Roles;
use App\Services\Configuration\BusinessSettingsService;
use App\Services\ProformaInvoices\PiAmounts;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * The PI screens: who may do what (spec §6), the sales own-client rule, the printed PI in
 * MASDAR's layout (S1) and the paid amount over every revision (plan: "PI paid amounts").
 */
class ProformaInvoiceScreensTest extends TestCase
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

    /** A PI for $client with one line of 12 ctn × 24 at 0.875 (252.0000), issued unless $draft. */
    private function pi(Party $client, bool $draft = false): ProformaInvoice
    {
        $manager = $this->make(Roles::MANAGER);
        $this->actingAs($manager);
        $pi = $this->service()->createDraft(['client_id' => $client->id, 'po_number' => 'PO-2026-15', 'po_date' => '2026-11-10'], $manager);
        $this->service()->addLine($pi, [
            'product_id' => Product::factory()->create(['name' => 'Ceramic mug'])->id,
            'cartons' => '12', 'qty_per_carton' => '24', 'unit_price' => '0.875', 'cbm_per_carton' => '0.042', 'kg_per_carton' => '8.2',
        ], $manager);

        return $draft ? $pi->fresh() : $this->service()->issue($pi, $manager);
    }

    private function receipt(ProformaInvoice $pi, string $amount, ?MoneyAccount $account = null, array $extra = []): Payment
    {
        $account ??= MoneyAccount::factory()->create();

        return Payment::create($extra + [
            'number' => 'PAY-T-'.(Payment::count() + 1), 'paid_on' => '2026-11-15', 'kind' => 'client_receipt', 'direction' => 'in',
            'money_account_id' => $account->id, 'currency' => $account->currency, 'amount' => $amount, 'rate' => 1,
            'party_id' => $pi->client_id, 'proforma_invoice_id' => $pi->id, 'user_id' => User::factory()->create()->id,
        ]);
    }

    public function test_staff_and_the_vendor_are_refused_and_each_level_is_enforced(): void
    {
        $pi = $this->pi(Party::factory()->client()->create());

        foreach ([Roles::STAFF, Roles::SYSTEM_ADMIN, Roles::WAREHOUSE_QC] as $role) {
            $user = $this->make($role);
            $this->actingAs($user)->get(route('proforma-invoices.index'))->assertForbidden();
            $this->get(route('proforma-invoices.show', $pi))->assertForbidden();
            $this->get(route('proforma-invoices.print', $pi))->assertForbidden();
            $this->post(route('proforma-invoices.accept', $pi))->assertForbidden();
        }

        // View only: reads, never writes.
        $this->actingAs($this->make(Roles::MERCHANDISER));
        $this->get(route('proforma-invoices.index'))->assertOk()->assertSee($pi->pi_number);
        $this->get(route('proforma-invoices.show', $pi))->assertOk()->assertDontSee(route('proforma-invoices.accept', $pi));
        $this->get(route('proforma-invoices.create'))->assertForbidden();
        $this->post(route('proforma-invoices.store'), ['client_id' => $pi->client_id])->assertForbidden();
        $this->post(route('proforma-invoices.accept', $pi))->assertForbidden();
        $this->post(route('proforma-invoices.revise', $pi))->assertForbidden();
        $this->assertSame('issued', $pi->fresh()->status);

        $this->actingAs($this->make(Roles::MANAGER));
        $this->get(route('proforma-invoices.index', ['status' => 'issued', 'search' => 'PI-']))->assertOk()->assertSee($pi->pi_number);
        $this->get(route('proforma-invoices.index', ['status' => 'draft']))->assertOk()->assertDontSee($pi->pi_number);
        $this->get(route('proforma-invoices.create'))->assertOk();
        $this->get(route('proforma-invoices.show', $pi))->assertOk()->assertSee(route('proforma-invoices.accept', $pi));
    }

    public function test_the_print_page_follows_masdars_layout_in_english(): void
    {
        $pi = $this->pi(Party::factory()->client()->create(['name' => 'Gulf Home Trading LLC']));
        $this->actingAs($this->make(Roles::SALES, Roles::MANAGER));

        $this->get(route('proforma-invoices.print', $pi))->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('images/masdar-logo.png')
            ->assertSeeInOrder([
                'MASDAR FOR TRADING CO., LIMITED',
                'Room 1608, Block B, Liandu Building, Futian Subdistrict, Yiwu, Zhejiang',
                'TEL:008613085650160-13173800074',
                'Proforma Invoice (PI)',
                'To:', 'Gulf Home Trading LLC', 'Shipped to:', 'PO NO:', 'PO-2026-15', 'PO DATE:', '2026-11-10',
                'No', 'PICTURE', 'ITEM NAME & DESCRIPTION', 'CTN', 'QTY/CTN', 'TOTAL QTY', 'UNIT', 'PRICE', 'AMOUNT', 'Total CBM', 'Total KG',
                'Ceramic mug', '12', '24', '288', 'Pieces', '$0.875', '$252.00', '0.504', '98.4',
                'Total', '288', '$252.00', '0.504', '98.4',
                '30% DEPOSIT 70% FINAL PAYMENT BEFORE SHIPPING',
            ])
            ->assertDontSee('DRAFT');

        $draft = $this->pi(Party::factory()->client()->create(), draft: true);
        $this->get(route('proforma-invoices.print', $draft))->assertOk()->assertSee('DRAFT');
    }

    public function test_sales_sees_only_the_pis_of_their_own_clients(): void
    {
        $mine = $this->make(Roles::SALES);
        $other = $this->make(Roles::SALES);
        $myPi = $this->pi(Party::factory()->client()->create(['account_manager_id' => $mine->id, 'name' => 'Client of mine']));
        $theirPi = $this->pi($theirClient = Party::factory()->client()->create(['account_manager_id' => $other->id, 'name' => 'Client of theirs']));

        $this->actingAs($mine);
        $this->get(route('proforma-invoices.index'))->assertOk()->assertSee($myPi->pi_number)->assertDontSee($theirPi->pi_number)
            ->assertDontSee('Client of theirs');
        $this->get(route('proforma-invoices.show', $myPi))->assertOk();
        $this->get(route('proforma-invoices.show', $theirPi))->assertNotFound();
        $this->get(route('proforma-invoices.print', $theirPi))->assertNotFound();
        $this->post(route('proforma-invoices.accept', $theirPi))->assertNotFound();
        $this->post(route('proforma-invoices.revise', $theirPi))->assertNotFound();
        $this->post(route('proforma-invoices.store'), ['client_id' => $theirClient->id])->assertSessionHasErrors('client_id');
        $this->assertSame('issued', $theirPi->fresh()->status);

        // A manager sees every client's PIs; so does sales once the option is off.
        $this->actingAs($this->make(Roles::MANAGER))->get(route('proforma-invoices.index'))->assertSee($myPi->pi_number)->assertSee($theirPi->pi_number);
        app(BusinessSettingsService::class)->update(['sales_own_clients_only' => false]);
        $this->actingAs($mine)->get(route('proforma-invoices.show', $theirPi))->assertOk();
    }

    public function test_the_paid_amount_sums_client_receipts_in_the_pis_currency_across_revisions(): void
    {
        $manager = $this->make(Roles::MANAGER, Roles::ACCOUNTANT);
        $first = $this->pi(Party::factory()->client()->create());
        $this->receipt($first, '50');
        $this->actingAs($manager);
        $second = $this->service()->createRevision($first, $manager);
        $this->service()->issue($second, $manager);

        $usd = MoneyAccount::factory()->create();
        $refunded = $this->receipt($second, '25', $usd);
        $this->receipt($second, '25', $usd);
        $this->receipt($second, '25', $usd, ['number' => 'PAY-T-REV', 'reverses_payment_id' => $refunded->id]); // a reversal counts negative
        $this->receipt($second, '1000', MoneyAccount::factory()->rmb()->create());                                // another currency: not counted

        $this->assertSame('75.0000', $second->fresh()->paidAmount());
        $this->assertSame('75.0000', $first->fresh()->paidAmount());
        $row = ProformaInvoice::query()->current()->withAmounts()->where('pi_number', $first->pi_number)->sole();
        $this->assertSame($second->id, $row->id);
        $this->assertSame('252.0000', $row->total_amount);
        $this->assertSame('75.0000', $row->paid_amount);

        $this->get(route('proforma-invoices.index'))->assertOk()->assertSeeInOrder(['$252.00', '$75.00', '$177.00']);
        $this->get(route('proforma-invoices.show', $second))->assertOk()
            ->assertSeeInOrder(['Paid', '$75.00', 'Deposit required', '$75.60', 'Balance due', '$177.00'])
            ->assertSee('PAY-T-REV')->assertSee('CNY 1,000.00');
    }

    public function test_supplier_and_supplier_price_are_shown_and_taken_only_from_those_who_may_see_them(): void
    {
        $sales = $this->make(Roles::SALES);
        $supplier = Party::factory()->supplier()->create(['name' => 'Yiwu Ceramics Factory']);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id]);
        $product = Product::factory()->create(['supplier_id' => $supplier->id, 'supplier_price' => '3.2000', 'supplier_currency' => 'CNY']);
        $pi = $this->pi($client, draft: true);

        $this->actingAs($sales)->get(route('proforma-invoices.show', $pi))->assertOk()
            ->assertDontSee('Supplier price')->assertDontSee('Yiwu Ceramics Factory');
        // Sales cannot set them; the product's usual supplier and price stand.
        $this->post(route('proforma-invoices.lines.store', $pi), [
            'form' => 'add', 'product' => (string) $product->id, 'cartons' => '3', 'unit_price' => '1', 'supplier_price' => '0.01',
        ])->assertSessionHasNoErrors();
        $line = PiLine::where('product_id', $product->id)->sole();
        $this->assertSame($supplier->id, $line->supplier_id);
        $this->assertSame('3.2000', $line->supplier_price);
        $this->assertSame('CNY', $line->supplier_currency);

        $this->actingAs($this->make(Roles::MANAGER))->get(route('proforma-invoices.show', $pi))->assertOk()
            ->assertSee('Supplier price')->assertSee('Yiwu Ceramics Factory')->assertSee('CNY 3.20');
        // The printed PI never shows a supplier (spec §12 reading).
        $this->get(route('proforma-invoices.print', $pi))->assertOk()->assertDontSee('Yiwu Ceramics Factory')->assertDontSee('3.20');
    }

    public function test_new_products_from_a_line_need_the_products_screen(): void
    {
        $logistics = $this->make(Roles::LOGISTICS); // views PIs and products, edits neither
        $pi = $this->pi(Party::factory()->client()->create(), draft: true);
        $this->actingAs($logistics)->post(route('proforma-invoices.lines.store', $pi), ['form' => 'add', 'product' => 'new'])->assertForbidden();

        $user = $this->make(Roles::STAFF);
        $role = Role::findByName(Roles::STAFF);
        $role->givePermissionTo(['proforma_invoices.view', 'proforma_invoices.edit']);
        $this->actingAs($user->fresh())->post(route('proforma-invoices.lines.store', $pi), [
            'form' => 'add', 'product' => 'new', 'description' => 'Bamboo tray', 'unit' => 'pcs', 'cartons' => '1', 'qty_per_carton' => '10', 'unit_price' => '2',
        ])->assertForbidden();
        $this->assertSame(0, Product::where('name', 'Bamboo tray')->count());
    }

    public function test_amounts_are_formatted_without_floats(): void
    {
        $this->assertSame('$1,234.50', PiAmounts::money('1234.5000', 'USD'));
        $this->assertSame('$0.00', PiAmounts::money('0.0000', 'USD'));
        $this->assertSame('CNY 12,345,678,901,234.5679', PiAmounts::money('12345678901234.5679', 'CNY'));
        $this->assertSame('-$3.00', PiAmounts::money('-3', 'usd'));
        $this->assertSame('288', PiAmounts::number('288.0000'));
        $this->assertSame('0.504', PiAmounts::number('0.5040'));
        $this->assertSame('75.6000', PiAmounts::depositRequired('252.0000', '30.00'));
        $this->assertSame('0.0002', PiAmounts::depositRequired('0.0005', '33.33'));
    }
}
