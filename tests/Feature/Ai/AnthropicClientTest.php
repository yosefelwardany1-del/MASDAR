<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiFeature;
use App\Services\Ai\AnthropicClient;
use App\Services\Ai\OpenRouterClient;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The Claude API behind every AI feature, without changing any feature.
 *
 * The SDK is given a fake PSR-18 transport, so these assert on the exact request the
 * API would receive and never touch the network. What matters: the container hands
 * out the Claude client the moment its key exists; system prompts and token budgets
 * are translated into what Claude expects; and every way Claude can fail becomes the
 * RuntimeException every feature already catches and falls back on.
 */
class AnthropicClientTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,array{request:RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.enabled' => true,
            'ai.api_key' => null,
            'ai.provider' => 'auto',
            'ai.anthropic.api_key' => 'test-key',
            'ai.anthropic.max_retries' => 0,
        ]);
    }

    // ---- helpers ----------------------------------------------------------------

    /** @param array<int,Response> $responses */
    private function client(array $responses): AnthropicClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new AnthropicClient('test-key', new Guzzle(['handler' => $stack]));
    }

    /** @param array<int,array<string,mixed>> $content */
    private function reply(array $content, string $stopReason = 'end_turn', array $extra = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(array_merge([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 42, 'output_tokens' => 7],
        ], $extra)));
    }

    private function error(int $status, string $type, string $message): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'type' => 'error', 'error' => ['type' => $type, 'message' => $message],
        ]));
    }

    /** @return array<string,mixed> */
    private function sentBody(): array
    {
        $this->assertNotEmpty($this->history, 'expected a request to reach the fake transport');

        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    // ---- provider selection -----------------------------------------------------

    public function test_the_container_hands_out_the_claude_client_when_its_key_is_present(): void
    {
        $this->assertInstanceOf(AnthropicClient::class, app(OpenRouterClient::class));

        config(['ai.anthropic.api_key' => null, 'ai.api_key' => 'or-key']);
        $this->assertNotInstanceOf(AnthropicClient::class, app(OpenRouterClient::class));

        // Forced the other way round, whatever keys exist.
        config(['ai.anthropic.api_key' => 'test-key', 'ai.provider' => 'openrouter']);
        $this->assertNotInstanceOf(AnthropicClient::class, app(OpenRouterClient::class));
    }

    public function test_every_feature_switch_reads_the_claude_key(): void
    {
        // No feature ships yet; each sub-project registers its own in config/ai.php.
        config(['ai.features.packing_list_draft' => ['enabled' => true, 'tier' => 'strong', 'data' => 'high']]);
        $this->assertTrue(AiFeature::enabled('packing_list_draft'), 'only ANTHROPIC_API_KEY is set, and that is enough');

        config(['ai.anthropic.api_key' => null]);
        $this->assertFalse(AiFeature::enabled('packing_list_draft'));
    }

    public function test_a_feature_nobody_registered_is_off(): void
    {
        $this->assertFalse(AiFeature::enabled('anything'));
    }

    // ---- the request Claude receives ---------------------------------------------

    public function test_chat_sends_what_claude_expects_and_returns_the_text(): void
    {
        $client = $this->client([$this->reply([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'pong'],
        ])]);

        $answer = $client->chat([
            ['role' => 'system', 'content' => 'You are terse.'],
            ['role' => 'user', 'content' => 'Say pong.'],
        ], 'strong', ['temperature' => 0.1, 'max_tokens' => 512]);

        $this->assertSame('pong', $answer, 'thinking blocks are not the answer');

        $body = $this->sentBody();
        $this->assertSame('claude-opus-5', $body['model']);
        $this->assertSame('You are terse.', $body['system'], 'system prompts are their own field on this API');
        $this->assertSame([['role' => 'user', 'content' => 'Say pong.']], $body['messages']);
        $this->assertGreaterThanOrEqual(4096, $body['max_tokens'], 'thinking counts against max_tokens, so small budgets are raised');
        $this->assertArrayNotHasKey('temperature', $body, 'rejected by the current models — dropped, not forwarded');
        $this->assertSame('high', $body['output_config']['effort'], 'the strong tier thinks hard');

        $request = $this->history[0]['request'];
        $this->assertSame('test-key', $request->getHeaderLine('x-api-key'));
        $this->assertNotSame('', $request->getHeaderLine('anthropic-version'));
        $this->assertStringContainsString('api.anthropic.com/v1/messages', (string) $request->getUri());
    }

    public function test_each_tier_has_its_own_effort(): void
    {
        $client = $this->client([$this->reply([['type' => 'text', 'text' => 'ok']])]);
        $client->chat([['role' => 'user', 'content' => 'classify']], 'cheap');

        $this->assertSame('low', $this->sentBody()['output_config']['effort']);
    }

    public function test_effort_is_not_sent_to_a_model_that_rejects_it(): void
    {
        config(['ai.anthropic.models.cheap' => 'claude-haiku-4-5']);
        $client = $this->client([$this->reply([['type' => 'text', 'text' => 'ok']])]);

        $client->chat([['role' => 'user', 'content' => 'classify']], 'cheap');

        $body = $this->sentBody();
        $this->assertSame('claude-haiku-4-5', $body['model']);
        $this->assertArrayNotHasKey('output_config', $body);
    }

    public function test_json_decodes_the_object_even_when_wrapped_in_prose(): void
    {
        $client = $this->client([$this->reply([
            ['type' => 'text', 'text' => "Here you go:\n{\"summary\": \"fine\", \"items\": []}\nHope that helps."],
        ])]);

        $decoded = $client->json('Extract things.', 'transcript…', 'strong');

        $this->assertSame(['summary' => 'fine', 'items' => []], $decoded);
        $this->assertStringContainsString('single JSON object', $this->sentBody()['system']);
    }

    // ---- every failure is the exception features already catch --------------------

    public function test_a_refusal_is_an_exception_not_a_blank_answer(): void
    {
        $client = $this->client([$this->reply([], 'refusal', [
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'not something I can help with'],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declined');
        $client->chat([['role' => 'user', 'content' => 'x']]);
    }

    public function test_a_starved_reply_says_so(): void
    {
        $client = $this->client([$this->reply([], 'max_tokens')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('max_tokens');
        $client->chat([['role' => 'user', 'content' => 'x']]);
    }

    public function test_a_rate_limit_is_named_as_one(): void
    {
        $client = $this->client([$this->error(429, 'rate_limit_error', 'slow down')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('429');
        $client->chat([['role' => 'user', 'content' => 'x']]);
    }

    public function test_a_bad_request_carries_the_api_message(): void
    {
        $client = $this->client([$this->error(400, 'invalid_request_error', 'temperature is not supported')]);

        try {
            $client->chat([['role' => 'user', 'content' => 'x']]);
            $this->fail('expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
            $this->assertStringContainsString('temperature is not supported', $e->getMessage());
        }
    }

    public function test_a_missing_key_never_reaches_the_network(): void
    {
        config(['ai.anthropic.api_key' => null]);
        $client = new AnthropicClient(null, new Guzzle(['handler' => HandlerStack::create(new MockHandler([]))]));

        $this->assertFalse($client->enabled());
        $this->expectException(RuntimeException::class);
        $client->chat([['role' => 'user', 'content' => 'x']]);
    }
}
