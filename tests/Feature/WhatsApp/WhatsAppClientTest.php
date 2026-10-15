<?php

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.enabled' => true,
            'whatsapp.phone_number_id' => '1234567890',
            'whatsapp.access_token' => 'env-token',
            'whatsapp.auth_template' => 'sign_in_code',
        ]);
    }

    public function test_the_token_comes_from_the_environment_only(): void
    {
        $this->assertSame('env-token', app(WhatsAppClient::class)->token());

        config(['whatsapp.access_token' => null]);
        $client = app(WhatsAppClient::class);
        $this->assertSame('', $client->token());
        $this->assertFalse($client->configured());
    }

    public function test_an_unconfigured_client_never_reaches_the_network(): void
    {
        Http::fake();
        config(['whatsapp.access_token' => null]);

        $this->assertFalse(app(WhatsAppClient::class)->sendAuthCode('+201012345678', '123456'));
        Http::assertNothingSent();
    }

    public function test_a_code_goes_out_as_the_approved_template(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->assertTrue(app(WhatsAppClient::class)->sendAuthCode('+201012345678', '123456'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1234567890/messages')
            && $r->hasHeader('Authorization', 'Bearer env-token')
            && $r['to'] === '201012345678'
            && $r['template']['name'] === 'sign_in_code'
            && $r['template']['components'][0]['parameters'][0]['text'] === '123456');
    }
}
