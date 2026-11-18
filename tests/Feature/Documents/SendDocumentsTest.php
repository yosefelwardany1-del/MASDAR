<?php

namespace Tests\Feature\Documents;

use App\Mail\DocumentMail;
use App\Models\Container;
use App\Models\DocumentSend;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Documents\PdfRenderer;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.8: documents as PDF, sent by email or WhatsApp, every attempt logged. */
class SendDocumentsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $this->manager = User::where('email', 'manager@example.test')->sole();
        $this->pi = ProformaInvoice::query()->current()->whereHas('client', fn ($q) => $q->where('name', 'Gulf Home Trading LLC'))->sole();
    }

    private function fakePdf(): void
    {
        $this->mock(PdfRenderer::class, function ($mock) {
            $mock->shouldReceive('available')->andReturn(true);
            $mock->shouldReceive('fromHtml')->andReturn('%PDF-1.4 test');
        });
    }

    public function test_a_pi_is_emailed_as_a_pdf_and_the_send_is_logged(): void
    {
        Mail::fake();
        $this->fakePdf();
        $client = $this->pi->client;
        PartyContact::create(['party_id' => $client->id, 'name' => 'Omar', 'email' => 'omar@gulfhome.example', 'is_primary' => true]);

        $this->actingAs($this->manager)->get(route('documents.send.create', ['type' => 'proforma-invoice', 'id' => $this->pi->id]))
            ->assertOk()->assertSee('omar@gulfhome.example')->assertSee($this->pi->reference());

        $this->actingAs($this->manager)->post(route('documents.send.store', ['type' => 'proforma-invoice', 'id' => $this->pi->id]), [
            'channel' => 'email', 'to' => ['omar@gulfhome.example'], 'cc' => 'boss@masdar.example',
            'subject' => 'Proforma Invoice', 'message' => 'Please find it attached.',
        ])->assertRedirect()->assertSessionHas('success');

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail) => $mail->hasTo('omar@gulfhome.example') && $mail->hasCc('boss@masdar.example')
            && str_ends_with($mail->filename, '.pdf') && $mail->pdf === '%PDF-1.4 test');
        $send = DocumentSend::sole();
        $this->assertSame(['proforma-invoice', $this->pi->id, 'email', 'sent'], [$send->document_type, $send->document_id, $send->channel, $send->status]);
        DB::beginTransaction();
        try {
            DB::table('document_sends')->where('id', $send->id)->delete();
            $this->fail('the send log is append-only');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }

        // A bad address is refused before anything is sent.
        $this->actingAs($this->manager)->post(route('documents.send.store', ['type' => 'proforma-invoice', 'id' => $this->pi->id]), [
            'channel' => 'email', 'to_other' => 'not-an-email', 'subject' => 'x',
        ])->assertSessionHasErrors('to');
    }

    public function test_whatsapp_sends_the_approved_document_template_and_a_refusal_is_logged(): void
    {
        $this->fakePdf();
        config(['whatsapp.phone_number_id' => '123', 'whatsapp.access_token' => 'token', 'whatsapp.document_template' => 'document_send']);
        Http::fake([
            '*/123/media' => Http::response(['id' => 'media-1']),
            '*/123/messages' => Http::sequence()->push(['messages' => [['id' => 'wamid']]])->push(['error' => ['message' => 'Template not approved', 'code' => 132001]], 400),
        ]);

        $send = fn () => $this->actingAs($this->manager)->post(route('documents.send.store', ['type' => 'proforma-invoice', 'id' => $this->pi->id]), [
            'channel' => 'whatsapp', 'phone' => '+971 50 123 4567',
        ]);
        $send()->assertSessionHas('success');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages')
            && $request['template']['name'] === 'document_send'
            && $request['template']['components'][0]['parameters'][0]['document']['id'] === 'media-1'
            && $request['template']['components'][1]['parameters'][0]['text'] === $this->pi->reference());

        $send()->assertSessionHas('error');
        $this->assertSame(['sent', 'failed'], DocumentSend::orderBy('id')->pluck('status')->all());
        $this->assertStringContainsString('Template not approved', DocumentSend::latest('id')->first()->error);
    }

    public function test_a_draft_never_leaves_and_seeing_follows_the_document(): void
    {
        $this->fakePdf();
        $draft = ProformaInvoice::query()->where('status', 'draft')->first()
            ?? app(ProformaInvoiceService::class)->createDraft(['client_id' => $this->pi->client_id], $this->manager);
        $this->actingAs($this->manager)->get(route('documents.send.create', ['type' => 'proforma-invoice', 'id' => $draft->id]))->assertOk()->assertSee(__('Only an issued PI is sent to the client.'));
        $this->actingAs($this->manager)->post(route('documents.send.store', ['type' => 'proforma-invoice', 'id' => $draft->id]), ['channel' => 'email', 'to_other' => 'a@b.example'])
            ->assertSessionHas('error');

        // Whoever may not see supplier prices may not have the purchase contract either.
        $order = SupplierOrder::query()->first();
        $sales = User::where('email', 'sales@example.test')->sole();
        $this->actingAs($sales)->get(route('documents.pdf', ['type' => 'purchase-contract', 'id' => $order->id]))->assertForbidden();
        $this->actingAs($this->manager)->get(route('documents.pdf', ['type' => 'purchase-contract', 'id' => $order->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $container = Container::sole();
        $client = Party::where('name', 'Gulf Home Trading LLC')->sole();
        $this->actingAs($this->manager)->get(route('documents.send.create', ['type' => 'commercial-invoice', 'id' => $container->id, 'client' => $client->id]))->assertOk()->assertSee('CI-');
        $this->actingAs($this->manager)->get(route('documents.pdf', ['type' => 'unknown', 'id' => 1]))->assertNotFound();
    }

    public function test_the_pdf_is_made_by_chromium_with_the_images_inside(): void
    {
        $renderer = app(PdfRenderer::class);
        $html = $renderer->inlineImages('<img src="'.asset('images/masdar-logo.png').'"><img src="https://elsewhere.example/x.png">');
        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('src="data:,"', $html, 'an image from elsewhere is dropped');

        // Chromium itself runs only where the machine names it (the Docker image installs it).
        if (! filled(config('documents.chrome_binary')) || ! $renderer->available()) {
            $this->markTestSkipped('PDF_CHROME_BINARY is not set on this machine.');
        }
        $pdf = $renderer->fromHtml('<html><body><h1>Proforma Invoice</h1></body></html>');
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
