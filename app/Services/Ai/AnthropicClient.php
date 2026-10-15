<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Message;
use Anthropic\RequestOptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * The Claude API, through the official Anthropic PHP SDK.
 *
 * Answers exactly the calls every feature already makes on OpenRouterClient —
 * chat(), json(), model(), enabled() — so switching the provider is a container
 * binding (AppServiceProvider) and a key, not a change to nineteen services. It
 * extends the OpenRouter class only because that class is what they all type-hint;
 * nothing of the OpenAI wire format is used here.
 *
 * WHAT IS DIFFERENT ABOUT CLAUDE, handled here so callers need not know:
 *
 *   - `system` is its own field, not a message. System entries in the messages
 *     array are lifted out and joined.
 *   - Thinking is on by default (adaptive) and its tokens count against max_tokens,
 *     so the small budgets features ask for are raised to a floor first — a
 *     classification with max_tokens 512 would otherwise return nothing.
 *   - temperature / top_p / top_k are rejected by the current models (HTTP 400), so
 *     they are dropped. Effort per tier (config ai.anthropic.effort) is the dial.
 *   - A safety refusal comes back as a normal 200 with stop_reason 'refusal'. It is
 *     turned into an exception here so every feature's existing fallback runs.
 *
 * Same failure policy as the parent: throws RuntimeException on any problem, and
 * every feature already catches that and falls back to its rule-based behaviour.
 */
class AnthropicClient extends OpenRouterClient
{
    private string $key;

    private ?Client $sdk = null;

    /**
     * @param  ClientInterface|null  $transporter  a PSR-18 client, injected by tests so
     *                                             nothing here ever reaches the network
     */
    public function __construct(?string $apiKey = null, private ?ClientInterface $transporter = null)
    {
        $this->key = (string) ($apiKey ?? config('ai.anthropic.api_key'));
    }

    public function enabled(): bool
    {
        return (bool) config('ai.enabled', true) && $this->key !== '';
    }

    /** A tier ('cheap'|'mid'|'strong') to a Claude model id. */
    public function model(string $tier): string
    {
        return (string) (config("ai.anthropic.models.{$tier}")
            ?? config('ai.anthropic.models.strong')
            ?? 'claude-opus-5');
    }

    public function chat(array $messages, string $tier = 'mid', array $opts = []): string
    {
        return $this->text($this->send($messages, $tier, $opts));
    }

    public function json(string $system, string $user, string $tier = 'mid', array $opts = []): array
    {
        // No response_format on this API. The instruction is enough for the current
        // models, and extractJson() strips any prose around the object.
        $system = rtrim($system)."\n\nRespond with a single JSON object and nothing else — no prose, no code fences.";

        $text = $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], $tier, $opts);

        $decoded = json_decode($this->extractJson($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Claude did not return valid JSON.');
        }

        return $decoded;
    }

    /**
     * The same contract as OpenRouterClient::structured(), through the Claude API's own
     * structured outputs (output_config.format), so the seam stays whole. `model` here is a
     * Claude API id ('claude-opus-5-5'); an OpenRouter slug falls back to the strong tier.
     * `effort` defaults to the strong tier's. Thinking is adaptive and always on for the
     * current models; its tokens count against max_tokens.
     *
     * @param  array<string,mixed>  $schema
     * @param  array{model?:string, max_tokens?:int, timeout?:int, effort?:string}  $opts
     *
     * @throws StructuredOutputException
     */
    public function structured(string $system, string $user, array $schema, string $name, array $opts = []): StructuredReply
    {
        if (! $this->enabled()) {
            throw new StructuredOutputException(StructuredOutputException::UNAVAILABLE, 'The Claude API is not configured (set ANTHROPIC_API_KEY).');
        }

        try {
            $message = $this->send([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], 'strong', [
                'model' => $opts['model'] ?? null,
                'max_tokens' => $opts['max_tokens'] ?? 16000,
                'timeout' => $opts['timeout'] ?? null,
                'effort' => $opts['effort'] ?? null,
                'output_schema' => $schema,
            ]);
        } catch (StructuredOutputException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StructuredOutputException(StructuredOutputException::HTTP, $e->getMessage(), $e);
        }

        // The Claude API reports tokens, not a price: the caller estimates the cost.
        $usage = new StructuredUsage((string) $message->model, $message->usage->inputTokens, $message->usage->outputTokens, null, (string) $message->id);
        if ($message->stopReason === 'max_tokens') {
            throw (new StructuredOutputException(StructuredOutputException::TRUNCATED, 'Claude ran out of output budget before finishing.'))->withUsage($usage);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $decoded = json_decode(trim($text), true);
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw (new StructuredOutputException(StructuredOutputException::INVALID_JSON, 'Claude did not return one JSON object.'))->withUsage($usage);
        }

        return new StructuredReply(
            data: $decoded,
            model: (string) $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            finishReason: $message->stopReason,
            requestId: $usage->requestId,
        );
    }

    /**
     * The whole reply as an array in the Claude API's own shape (id, model, content,
     * stop_reason, usage) — NOT the OpenAI shape the parent returns. Nothing in the
     * app calls raw() today; kept so the two clients have the same surface.
     *
     * @return array<string,mixed>
     */
    public function raw(array $messages, string $tier = 'mid', array $opts = []): array
    {
        $message = $this->send($messages, $tier, $opts);

        return [
            'id' => $message->id,
            'model' => $message->model,
            'stop_reason' => $message->stopReason,
            'content' => array_map(fn ($block) => $block->toProperties(), $message->content),
            'usage' => [
                'input_tokens' => $message->usage->inputTokens,
                'output_tokens' => $message->usage->outputTokens,
                'cache_read_input_tokens' => $message->usage->cacheReadInputTokens,
                'cache_creation_input_tokens' => $message->usage->cacheCreationInputTokens,
            ],
        ];
    }

    // -----------------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------------

    /**
     * @param  array<int,array{role:string,content:string}>  $messages
     * @param  array<string,mixed>  $opts
     */
    private function send(array $messages, string $tier, array $opts): Message
    {
        if (! $this->enabled()) {
            throw new RuntimeException('The Claude API is not configured (set ANTHROPIC_API_KEY).');
        }

        $this->pace();

        [$system, $turns] = $this->split($messages);
        // A caller may pin a Claude API id (structured()); never an OpenRouter slug.
        $model = filled($opts['model'] ?? null) && ! str_contains((string) $opts['model'], '/')
            ? (string) $opts['model']
            : $this->model($tier);

        $floor = (int) config('ai.anthropic.min_max_tokens', 4096);
        $maxTokens = max($floor, (int) ($opts['max_tokens'] ?? config('ai.max_tokens', 2000)));

        $params = [
            'maxTokens' => $maxTokens,
            'messages' => $turns,
            'model' => $model,
            'requestOptions' => [
                'timeout' => (float) ($opts['timeout'] ?? config('ai.anthropic.timeout', 120)),
                'maxRetries' => (int) config('ai.anthropic.max_retries', 2),
            ],
        ];

        if ($system !== '') {
            $params['system'] = $system;
        }

        // Effort is the cost/quality dial on the current models. Haiku 4.5 and the 4.5
        // Sonnet reject the parameter, so it is only sent where it is understood.
        $effort = (string) ($opts['effort'] ?? config("ai.anthropic.effort.{$tier}", ''));
        if ($effort !== '' && ! preg_match('/haiku|sonnet-4-5/', $model)) {
            $params['outputConfig'] = ['effort' => $effort];
        }
        // Structured outputs: the API itself holds the reply to this JSON schema.
        if (! empty($opts['output_schema']) && is_array($opts['output_schema'])) {
            $params['outputConfig'] = ($params['outputConfig'] ?? []) + [
                'format' => ['type' => 'json_schema', 'schema' => $opts['output_schema']],
            ];
        }

        if (! empty($opts['stop'])) {
            $params['stopSequences'] = (array) $opts['stop'];
        }

        try {
            $message = $this->sdk()->messages->create(...$params);
        } catch (AuthenticationException $e) {
            throw new RuntimeException('The Claude API rejected the API key (401 — check ANTHROPIC_API_KEY).', 0, $e);
        } catch (RateLimitException $e) {
            throw new RuntimeException('The Claude API is rate-limiting this account (429). Try again shortly.', 0, $e);
        } catch (APIStatusException $e) {
            $type = $e->type?->value ? " {$e->type->value}" : '';
            throw new RuntimeException("Claude API error {$e->status}{$type}: ".$this->detail($e), 0, $e);
        } catch (APIConnectionException $e) {
            throw new RuntimeException('Could not reach the Claude API: '.Str::limit($e->getMessage(), 160), 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            $why = $message->stopDetails?->explanation;
            // Still a RuntimeException for every existing caller; the reason code is for structured().
            throw new StructuredOutputException(StructuredOutputException::REFUSED, 'Claude declined this request'.($why ? ': '.$why : '.'));
        }

        Log::debug('Claude API call', [
            'model' => $message->model,
            'tier' => $tier,
            'input_tokens' => $message->usage->inputTokens,
            'output_tokens' => $message->usage->outputTokens,
            'cache_read' => $message->usage->cacheReadInputTokens,
            'stop_reason' => $message->stopReason,
        ]);

        return $message;
    }

    /** The reply's text blocks, joined. Thinking blocks are not the answer. */
    private function text(Message $message): string
    {
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException($message->stopReason === 'max_tokens'
                ? 'Claude ran out of output budget before writing anything (raise max_tokens).'
                : 'Claude returned an empty reply.');
        }

        return $text;
    }

    /**
     * System entries out of the message list, into the API's own field.
     *
     * @param  array<int,array{role:string,content:string}>  $messages
     * @return array{0:string,1:array<int,array{role:string,content:string}>}
     */
    private function split(array $messages): array
    {
        $system = [];
        $turns = [];

        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $content = (string) ($m['content'] ?? '');

            if ($role === 'system') {
                $system[] = $content;

                continue;
            }

            $turns[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $content];
        }

        return [trim(implode("\n\n", $system)), $turns];
    }

    private function detail(APIStatusException $e): string
    {
        $body = $e->body ?? null;
        $message = is_array($body) ? ($body['error']['message'] ?? null) : null;

        return Str::limit((string) ($message ?: $e->getMessage()), 200);
    }

    private function sdk(): Client
    {
        if ($this->sdk) {
            return $this->sdk;
        }

        $options = $this->transporter
            ? RequestOptions::with(transporter: $this->transporter)
            : null;

        return $this->sdk = new Client(
            apiKey: $this->key,
            baseUrl: filled(config('ai.anthropic.base_url')) ? (string) config('ai.anthropic.base_url') : null,
            requestOptions: $options,
        );
    }
}
