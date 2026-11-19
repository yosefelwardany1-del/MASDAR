<?php

namespace Tests\Feature\Integration;

use App\Models\BusinessSetting;
use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Security\Roles;
use App\Services\Shipping\ContainerTrackingService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Spec Q-C4: a client hears over WhatsApp when their container reaches a status MASDAR chose,
 * only when switched on, through the approved template, never on a correction, and every
 * attempt is logged with the number shown only by its last four digits.
 */
class WhatsAppContainerUpdatesTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.phone_number_id' => '1234567890', 'whatsapp.access_token' => 'test-token', 'whatsapp.enabled' => true]);
        $this->manager = User::factory()->create()->assignRole(Roles::MANAGER);

        $client = Party::factory()->client()->create(['name' => 'Gulf Home Trading']);
        PartyContact::create(['party_id' => $client->id, 'name' => 'Ahmed', 'whatsapp' => '+971501234567', 'is_primary' => true]);
        $pi = ProformaInvoice::factory()->create(['client_id' => $client->id]);
        $line = SupplierOrderLine::factory()->create(['pi_line_id' => PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'line_no' => 1])->id]);
        GoodsReceipt::create(['supplier_order_line_id' => $line->id, 'received_on' => now()->toDateString(), 'cartons' => 10, 'inspection_result' => 'pass', 'user_id' => $this->manager->id]);

        $shipping = app(ShipmentService::class);
        $shipment = $shipping->create(['mode' => 'sea_fcl', 'port_of_discharge' => 'JEA', 'eta' => now()->addDays(20)->toDateString()], $this->manager);
        $this->container = $shipping->addContainer($shipment, ['container_no' => 'MSKU7712342', 'container_type' => '40HQ'], $this->manager);
        $shipping->bookOrder($shipment, $line->supplierOrder, $this->manager);
        app(ContainerTrackingService::class)->load($this->container, $line, '10', now()->toDateString(), $this->manager);
    }

    private function recordStatus(string $code, ?int $corrects = null)
    {
        return app(ContainerTrackingService::class)->recordStatus($this->container, ['status' => $code, 'happened_at' => now()->toDateTimeString(), 'corrects_event_id' => $corrects], $this->manager);
    }

    public function test_nothing_is_sent_while_switched_off(): void
    {
        Http::fake();
        $this->recordStatus('on_board');

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_a_chosen_status_sends_the_client_the_template_and_logs_it(): void
    {
        BusinessSetting::current()->fill(['whatsapp_container_updates' => true, 'whatsapp_update_statuses' => 'on_board,arrived'])->save();
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->recordStatus('loaded_sealed');
        Http::assertNothingSent();

        $onBoard = $this->recordStatus('on_board');
        Http::assertSent(fn ($request) => $request['to'] === '971501234567'
            && $request['template']['name'] === 'container_update'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Gulf Home Trading'
            && $request['template']['components'][0]['parameters'][1]['text'] === 'MSKU7712342'
            && str_contains($request['template']['components'][0]['parameters'][3]['text'], 'Jebel Ali'));

        $log = WhatsAppMessage::sole();
        $this->assertTrue($log->sent);
        $this->assertSame('4567', $log->to_last4);
        $this->assertSame($onBoard->id, $log->container_event_id);

        // A correction going back never messages the client.
        $this->recordStatus('loaded_sealed', $onBoard->id);
        $this->assertSame(1, WhatsAppMessage::count());
    }

    public function test_a_refusal_from_meta_is_logged_and_the_status_still_stands(): void
    {
        BusinessSetting::current()->fill(['whatsapp_container_updates' => true])->save();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Template not approved', 'code' => 132001]], 400)]);

        $event = $this->recordStatus('on_board');

        $this->assertNotNull($event->fresh());
        $log = WhatsAppMessage::sole();
        $this->assertFalse($log->sent);
        $this->assertStringContainsString('Template not approved', $log->error);
    }

    public function test_the_options_screen_saves_the_switch_and_statuses(): void
    {
        $s = BusinessSetting::current();
        $payload = $s->only(['company_address', 'company_phone', 'pi_currency', 'supplier_currency', 'book_currency', 'pi_terms_template', 'step_warning_days', 'eta_warning_days',
            'client_prefix', 'supplier_prefix', 'shipping_company_prefix', 'product_prefix', 'pi_prefix', 'supplier_order_prefix', 'shipment_prefix', 'payment_prefix', 'journal_prefix'])
            + ['default_deposit_percent' => '30', 'whatsapp_container_updates' => '1', 'whatsapp_update_statuses' => ['arrived', 'delivered']];

        $this->actingAs($this->manager)->put(route('configuration.options.update'), $payload)->assertRedirect();

        $this->assertTrue(BusinessSetting::current()->whatsapp_container_updates);
        $this->assertSame(['arrived', 'delivered'], BusinessSetting::current()->whatsappUpdateStatuses());

        // The form always sends one empty value: unticking every status clears them.
        $this->actingAs($this->manager)->put(route('configuration.options.update'), ['whatsapp_update_statuses' => ['']] + $payload)->assertRedirect();
        $this->assertSame([], BusinessSetting::current()->whatsappUpdateStatuses());
        $this->actingAs($this->manager)->put(route('configuration.options.update'), ['whatsapp_update_statuses' => ['', 'nonsense']] + $payload)->assertSessionHasErrors();
    }
}
