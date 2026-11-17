<?php

namespace Tests\Feature\Integration;

use App\Models\ErpNotification;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Security\Roles;
use App\Services\Dashboard\AttentionQueries;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §7: a PI booked to ship while not paid in full reaches its account manager and the accountant. */
class UnpaidBeforeShippingTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_a_booked_unpaid_pi_is_flagged_until_paid(): void
    {
        $manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $sales = User::factory()->create()->assignRole(Roles::SALES);
        $otherSales = User::factory()->create()->assignRole(Roles::SALES);
        $accountant = User::factory()->create()->assignRole(Roles::ACCOUNTANT);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id]);
        $pi = ProformaInvoice::factory()->create(['client_id' => $client->id]);
        // 10 ctn × 48 × 1.25 = 600.00 USD
        $line = PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'line_no' => 1, 'cartons' => 10, 'qty_per_carton' => 48, 'unit_price' => '1.25']);
        $pi->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $manager->id]);
        $order = SupplierOrderLine::factory()->create(['pi_line_id' => $line->id])->supplierOrder;

        $attention = app(AttentionQueries::class);
        $this->assertCount(0, $attention->unpaidBooked(), 'not booked yet: nothing to flag');

        $shipping = app(ShipmentService::class);
        $shipping->bookOrder($shipping->create(['mode' => 'sea_fcl'], $manager), $order, $manager);
        $this->assertCount(1, $attention->unpaidBooked());
        $this->assertCount(1, $attention->unpaidBooked($sales->id));
        $this->assertCount(0, $attention->unpaidBooked($otherSales->id));

        $this->artisan('masdar:daily-alerts')->assertSuccessful();
        $this->assertStringContainsString('not paid in full', (string) ErpNotification::where('user_id', $accountant->id)->value('message'));
        $this->assertStringContainsString('not paid in full', (string) ErpNotification::where('user_id', $sales->id)->value('message'));
        $this->assertSame(0, ErpNotification::where('user_id', $otherSales->id)->count());

        Payment::create(['number' => 'PAY-T-1', 'paid_on' => now()->toDateString(), 'kind' => 'client_receipt', 'direction' => 'in',
            'money_account_id' => MoneyAccount::factory()->create()->id, 'currency' => 'USD', 'amount' => '600', 'rate' => 1,
            'party_id' => $client->id, 'proforma_invoice_id' => $pi->id, 'user_id' => $manager->id]);
        $this->assertCount(0, $attention->unpaidBooked(), 'paid in full');
    }
}
