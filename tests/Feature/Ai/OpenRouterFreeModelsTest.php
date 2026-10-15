<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\OpenRouterClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Free models only, from 18 Sep 2026: the defaults are OpenRouter's free router, a
 * model that is gone or wants credit hands over to the next in the chain, a reply cap
 * lower than asked is met halfway, and the model that actually answered is recorded.
 */
class OpenRouterFreeModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.api_key' => 'or-test', 'ai.rate_limit.max_retries' => 1, 'ai.min_max_tokens' => 512]);
    }

    private function ok(string $model, string $content = '{"ok":true}'): array
    {
        return ['model' => $model, 'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]];
    }

    public function test_every_tier_defaults_to_the_free_router_and_the_chain_ends_on_it(): void
    {
        $client = new OpenRouterClient;

        foreach (['cheap', 'mid', 'strong'] as $tier) {
            $this->assertSame('openrouter/free', $client->model($tier), $tier);
        }
        $this->assertSame(['openrouter/free'], $client->candidates('openrouter/free'));

        config(['ai.models.fallbacks' => ['meta-llama/llama-3.3-70b-instruct:free', 'openrouter/free']]);
        $this->assertSame(
            ['qwen/qwen3-235b-a22b:free', 'meta-llama/llama-3.3-70b-instruct:free', 'openrouter/free'],
            $client->candidates('qwen/qwen3-235b-a22b:free'),
        );
    }

    public function test_a_withdrawn_or_paid_slug_hands_over_to_the_next_model(): void
    {
        config(['ai.models.strong' => 'openai/gpt-oss-20b:free', 'ai.models.fallbacks' => ['qwen/qwen3-235b-a22b:free']]);
        Http::fake(function ($request) {
            $model = data_get($request->data(), 'model');

            return match ($model) {
                'openai/gpt-oss-20b:free' => Http::response(['error' => ['message' => 'No endpoints found for openai/gpt-oss-20b:free.']], 404),
                'qwen/qwen3-235b-a22b:free' => Http::response(['error' => ['message' => 'This model requires credits.']], 402),
                default => Http::response($this->ok('nvidia/nemotron-3-ultra:free')),
            };
        });

        $client = new OpenRouterClient;
        $reply = $client->json('system', 'user', 'strong');

        $this->assertSame(['ok' => true], $reply);
        $this->assertSame('nvidia/nemotron-3-ultra:free', $client->lastModel(), 'the router names the model it picked');
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => data_get($r->data(), 'model') === 'openrouter/free');
    }

    public function test_a_model_that_answers_stops_the_chain(): void
    {
        config(['ai.models.mid' => 'openai/gpt-oss-20b:free']);
        Http::fake(['openrouter.ai/*' => Http::response($this->ok('openai/gpt-oss-20b:free', 'pong'))]);

        $this->assertSame('pong', (new OpenRouterClient)->chat([['role' => 'user', 'content' => 'ping']], 'mid'));
        Http::assertSentCount(1);
    }

    public function test_when_nothing_can_answer_the_error_names_every_model_tried(): void
    {
        config(['ai.models.mid' => 'openai/gpt-oss-20b:free']);
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'No endpoints found']], 404)]);

        try {
            (new OpenRouterClient)->chat([['role' => 'user', 'content' => 'ping']], 'mid');
            $this->fail('expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('openai/gpt-oss-20b:free (404 No endpoints found)', $e->getMessage());
            $this->assertStringContainsString('openrouter/free (404 No endpoints found)', $e->getMessage());
        }
    }

    public function test_a_reply_cap_below_what_was_asked_is_met_halfway_on_the_same_model(): void
    {
        $budgets = [];
        Http::fake(function ($request) use (&$budgets) {
            $budgets[] = (int) data_get($request->data(), 'max_tokens');

            return count($budgets) === 1
                ? Http::response(['error' => ['message' => 'max_tokens must be at most 8192 for this model']], 400)
                : Http::response($this->ok('openrouter/free'));
        });

        (new OpenRouterClient)->json('s', 'u', 'strong', ['max_tokens' => 12000]);

        $this->assertSame([12000, 6000], $budgets);
        Http::assertSentCount(2);
    }

    public function test_any_other_error_is_that_models_answer_and_is_thrown(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Prompt blocked by data policy']], 400)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter API error 400: Prompt blocked by data policy');
        (new OpenRouterClient)->chat([['role' => 'user', 'content' => 'ping']], 'mid');
    }
}
