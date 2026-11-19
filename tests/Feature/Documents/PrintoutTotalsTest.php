<?php

namespace Tests\Feature\Documents;

use App\Models\Party;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Security\Roles;
use App\Services\Quotations\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * B30: the quotation printout totals what the screen totals, with cartons, CBM and KG when known;
 * the PI totals its cartons, and its quantities only when every line is in the same unit.
 */
class PrintoutTotalsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function manager(): User
    {
        app()->setLocale('en');

        return User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
    }

    public function test_the_quotation_printout_shows_its_total_cartons_cbm_and_kg(): void
    {
        $manager = $this->manager();
        $service = app(QuotationService::class);
        $q = $service->create(['client_id' => Party::factory()->client()->create()->id, 'currency' => 'USD', 'quoted_on' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString()], $manager);
        $service->addLine($q, ['description' => 'Glass jar 500ml', 'unit' => 'pcs', 'qty_per_carton' => '24', 'cartons' => '10', 'unit_price' => '1.20',
            'cbm_per_carton' => '0.05', 'kg_per_carton' => '8'], $manager);
        $service->addLine($q, ['description' => 'Lid', 'unit' => 'pcs', 'qty_per_carton' => '12', 'cartons' => '5', 'unit_price' => '2.50',
            'cbm_per_carton' => '0.04', 'kg_per_carton' => '6'], $manager);

        // 10 × 24 × 1.20 + 5 × 12 × 2.50 = 288 + 150; 15 cartons; 0.5 + 0.2 CBM; 80 + 30 KG.
        $this->assertSame('438.00', $q->fresh()->total());
        $this->actingAs($manager)->get(route('quotations.print', $q))->assertOk()
            ->assertSeeInOrder(['Total', '15', '$438.00'], false)
            ->assertSee('<b>Total:</b> $438.00', false)->assertSee('<b>Cartons:</b> 15', false)
            ->assertSee('<b>CBM:</b> 0.7', false)->assertSee('<b>KG:</b> 110', false);

        // A line without its CBM: the CBM is not known, so it is not printed; the KG still is.
        $service->addLine($q->fresh(), ['description' => 'Gift box', 'unit' => 'pcs', 'qty_per_carton' => '10', 'cartons' => '2', 'unit_price' => '3',
            'kg_per_carton' => '5'], $manager);
        $this->actingAs($manager)->get(route('quotations.print', $q))->assertOk()
            ->assertSee('<b>Total:</b> $498.00', false)->assertSee('<b>Cartons:</b> 17', false)
            ->assertDontSee('<b>CBM:</b>', false)->assertSee('<b>KG:</b> 120', false);
    }

    public function test_the_pi_printout_totals_cartons_and_adds_quantities_of_one_unit_only(): void
    {
        $manager = $this->manager();
        $pi = ProformaInvoice::factory()->create(['created_by' => $manager->id]);
        PiLine::factory()->for($pi, 'proformaInvoice')->create(['line_no' => 1, 'unit' => 'pcs', 'cartons' => 10, 'qty_per_carton' => 10, 'unit_price' => '1.10']);
        PiLine::factory()->for($pi, 'proformaInvoice')->create(['line_no' => 2, 'unit' => 'box', 'cartons' => 5, 'qty_per_carton' => 100, 'unit_price' => '0.30']);

        // 100 Pieces + 500 Boxes is not 600 of anything: no quantity total, but 15 cartons.
        $totals = $pi->lineTotals();
        $this->assertNull($totals->total_qty);
        $this->assertSame('15.0000', $totals->cartons);
        $print = $this->actingAs($manager)->get(route('proforma-invoices.print', $pi))->assertOk();
        $row = $this->totalRow($print->getContent());
        $this->assertStringContainsString('<td class="num">15</td>', $row);
        $this->assertStringNotContainsString('600', $row);
        $this->actingAs($manager)->get(route('proforma-invoices.show', $pi))->assertOk();

        // Every line in pieces: the quantities add up, and the row says in what.
        $same = ProformaInvoice::factory()->create(['created_by' => $manager->id]);
        PiLine::factory()->for($same, 'proformaInvoice')->create(['line_no' => 1, 'unit' => 'pcs', 'cartons' => 10, 'qty_per_carton' => 10]);
        PiLine::factory()->for($same, 'proformaInvoice')->create(['line_no' => 2, 'unit' => 'pcs', 'cartons' => 5, 'qty_per_carton' => 100]);
        $this->assertSame('600.0000', $same->lineTotals()->total_qty);
        $row = $this->totalRow($this->actingAs($manager)->get(route('proforma-invoices.print', $same))->getContent());
        $this->assertStringContainsString('<td class="num">600</td>', $row);
        $this->assertStringContainsString('<td class="num">15</td>', $row);
    }

    private function totalRow(string $html): string
    {
        $this->assertSame(1, preg_match('/<tr class="total">(.*?)<\/tr>/s', $html, $m), 'the printout has a total row');

        return $m[1];
    }
}
