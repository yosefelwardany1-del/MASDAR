<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Thin, throttled HTTP wrapper around OpenRouter's OpenAI-compatible chat API.
 *
 * This is the ONE place the app talks to an LLM. Every feature goes through here, so the
 * API key, retries, pacing and error handling live in a single spot.
 *
 * Every feature type-hints THIS class, which makes it the seam: when the Claude API is
 * the configured provider (see AiProvider), the container resolves it to
 * AnthropicClient, a subclass that answers chat()/json()/model() through the official
 * SDK. Features never know which one they got.
 *
 * It does NOT know about any business domain. Callers pass messages / a system+user prompt and
 * get back text or a decoded JSON array. Feature providers own the prompts and fallbacks.
 *
 * Failure policy: throws RuntimeException on any problem (missing key, non-2xx, bad JSON).
 * Callers are expected to catch and fall back to deterministic behaviour, so AI being down
 * never breaks the app.
 */
class OpenRouterClient
{
    public function __construct(private ?string $apiKey = null, private ?string $baseUrl = null)
    {
        $this->apiKey ??= (string) config('ai.api_key');
        $this->baseUrl = rtrim((string) ($baseUrl ?? config('ai.base_url')), '/');
    }

    /** True when AI is switched on AND a key is present — the gate every feature checks. */
    public function enabled(): bool
    {
        return (bool) config('ai.enabled', true) && filled($this->apiKey);
    }

    /**
     * Send a chat completion and return the assistant's text.
     *
     * @param  array<int,array{role:string,content:string}>  $messages
     * @param  string  $tier  cheap|mid|strong (resolved via config('ai.models'))
     * @param  array  $opts  merged into the request body (max_tokens, temperature, …)
     */
    public function chat(array $messages, string $tier = 'mid', array $opts = []): string
    {
        $choice = data_get($this->raw($messages, $tier, $opts), 'choices.0', []);
        $content = trim((string) data_get($choice, 'message.content', ''));

        if ($content === '') {
            // A reasoning model that ran out of budget mid-thought returns finish_reason
            // 'length' with NO content. Fail loudly so the caller falls back, rather than
            // handing back an empty string that looks like a valid (blank) answer.
            $finish = (string) data_get($choice, 'finish_reason', '');
            throw new RuntimeException($finish === 'length'
                ? 'OpenRouter reply was truncated before any content was produced (max_tokens too small for this model).'
                : 'OpenRouter returned an empty reply.');
        }

        return $content;
    }

    /**
     * Send a system+user prompt, force a JSON object back, and return it decoded.
     * Use this for structured features (scoring, extraction, categorisation).
     *
     * @return array<mixed>
     */
    public function json(string $system, string $user, string $tier = 'mid', array $opts = []): array
    {
        $opts['response_format'] = ['type' => 'json_object'];

        $text = $this->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ], $tier, $opts);

        $decoded = json_decode($this->extractJson($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenRouter did not return valid JSON.');
        }

        return $decoded;
    }

    /**
     * Ask ONE PINNED model for ONE JSON object in a STRICT schema.
     *
     * Not json() with a stricter format, because two of json()'s guarantees are the wrong
     * ones here. raw() walks a fallback chain that ENDS ON THE FREE ROUTER when a model is
     * gone, out of credit or busy — fine for a classification, unacceptable for a caller
     * sending a customer's documents, which must never reach a free model that may log or
     * train on them. So this makes exactly one request, to exactly the model given, and tells
     * OpenRouter not to fall back either (`allow_fallbacks: false`), to route only to
     * providers that honour every parameter sent (`require_parameters: true` — a provider
     * that would ignore the schema is not one to use), and only to providers that do not
     * store prompts (`data_collection: "deny"`). The client's own retries (429 / 5xx) stay:
     * they repeat the same request to the same model.
     *
     * Everything that is not one JSON object from that model throws, with a reason code:
     * a refusal, a reply cut off by max_tokens, an empty or non-JSON reply, or a reply that
     * names another model. The schema is enforced by the provider, but a caller must still
     * validate what comes back — range limits, for one, are not expressible in it.
     *
     * @param  array<string,mixed>  $schema  a JSON schema; every object additionalProperties:false
     * @param  array{model?:string, max_tokens?:int, timeout?:int, reasoning?:?string}  $opts  `model` is REQUIRED
     *
     * @throws StructuredOutputException
     */
    public function structured(string $system, string $user, array $schema, string $name, array $opts = []): StructuredReply
    {
        if (! $this->enabled()) {
            throw new StructuredOutputException(StructuredOutputException::UNAVAILABLE, 'OpenRouter is not configured (set OPENROUTER_API_KEY).');
        }

        $model = trim((string) ($opts['model'] ?? ''));
        if ($model === '') {
            throw new StructuredOutputException(StructuredOutputException::UNAVAILABLE, 'A structured call needs a pinned model.');
        }

        $this->pace();
        $this->lastModel = null;

        $payload = [
            'model' => $model,
            'max_tokens' => max((int) config('ai.min_max_tokens', 512), (int) ($opts['max_tokens'] ?? config('ai.max_tokens', 2000))),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $name, 'strict' => true, 'schema' => $schema],
            ],
            'provider' => [
                'allow_fallbacks' => false,
                'require_parameters' => true,
                'data_collection' => 'deny',
            ],
            // OpenRouter's usage accounting: the reply's `usage` carries what the call COST,
            // which is what finance is billed (the Omni-Screener's spend report).
            'usage' => ['include' => true],
        ];
        $effort = trim((string) ($opts['reasoning'] ?? ''));
        if ($effort !== '') {
            $payload['reasoning'] = ['effort' => $effort];
        }

        try {
            $response = $this->request((int) ($opts['timeout'] ?? config('ai.timeout', 60)))
                ->post($this->baseUrl.'/chat/completions', $payload);
        } catch (\Throwable $e) {
            throw new StructuredOutputException(StructuredOutputException::HTTP, 'Could not reach OpenRouter: '.Str::limit($e->getMessage(), 160), $e);
        }

        if ($response->status() === 401) {
            throw new StructuredOutputException(StructuredOutputException::HTTP, 'OpenRouter rejected the API key (401 — check OPENROUTER_API_KEY).');
        }
        if ($response->failed()) {
            $msg = (string) data_get($response->json(), 'error.message', '');
            $detail = $msg !== '' ? $msg : Str::limit(trim((string) $response->body()), 200);
            throw new StructuredOutputException(StructuredOutputException::HTTP, "OpenRouter API error {$response->status()}: ".Str::limit($detail, 200));
        }

        $body = $response->json() ?? [];
        $served = trim((string) data_get($body, 'model', ''));
        $this->lastModel = $served !== '' ? $served : $model;
        // From here on a reply came back and was paid for, usable or not.
        $usage = new StructuredUsage(
            model: $this->lastModel,
            inputTokens: is_numeric(data_get($body, 'usage.prompt_tokens')) ? (int) data_get($body, 'usage.prompt_tokens') : null,
            outputTokens: is_numeric(data_get($body, 'usage.completion_tokens')) ? (int) data_get($body, 'usage.completion_tokens') : null,
            cost: is_numeric(data_get($body, 'usage.cost')) ? (float) data_get($body, 'usage.cost') : null,
            requestId: filled(data_get($body, 'id')) ? Str::limit((string) data_get($body, 'id'), 120, '') : null,
        );
        if ($served !== '' && ! $this->isSameModel($model, $served)) {
            throw (new StructuredOutputException(StructuredOutputException::WRONG_MODEL, "Asked {$model}; {$served} answered."))->withUsage($usage);
        }

        $choice = (array) data_get($body, 'choices.0', []);
        $finish = (string) data_get($choice, 'finish_reason', '');
        if (filled(data_get($choice, 'message.refusal')) || $finish === 'content_filter') {
            throw (new StructuredOutputException(StructuredOutputException::REFUSED, 'The model declined the request.'))->withUsage($usage);
        }
        if ($finish === 'length') {
            throw (new StructuredOutputException(StructuredOutputException::TRUNCATED, 'The reply was cut off by max_tokens.'))->withUsage($usage);
        }

        $content = trim((string) data_get($choice, 'message.content', ''));
        $decoded = $content === '' ? null : json_decode($content, true);
        if (! is_array($decoded)) {
            // A provider that wraps the object in a fence is tolerated; anything else is not.
            $decoded = json_decode($this->extractJson($content), true);
        }
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw (new StructuredOutputException(StructuredOutputException::INVALID_JSON, 'The reply was not one JSON object.'))->withUsage($usage);
        }

        return new StructuredReply(
            data: $decoded,
            model: $this->lastModel,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            finishReason: $finish !== '' ? $finish : null,
            cost: $usage->cost,
            requestId: $usage->requestId,
        );
    }

    /**
     * Did the model asked for answer? OpenRouter may name the model it served differently
     * from the slug it was asked for (a dated or re-ordered id of the same model), so an
     * exact match is too strict. What the pin exists to stop is a reply from somewhere
     * ELSE — the free router, another vendor, a ':free' variant — so the served model must
     * come from the same vendor and must not be a free one.
     */
    private function isSameModel(string $asked, string $served): bool
    {
        if ($served === $asked || str_starts_with($served, $asked.'-')) {
            return true;
        }
        $vendor = fn (string $id) => strtolower((string) strstr($id, '/', true));

        return $vendor($asked) !== '' && $vendor($asked) !== 'openrouter'
            && $vendor($served) === $vendor($asked)
            && ! str_ends_with(strtolower($served), ':free');
    }

    /** The model that actually answered the last call — the router names the one it picked. */
    private ?string $lastModel = null;

    public function lastModel(): ?string
    {
        return $this->lastModel;
    }

    /**
     * The models to try for a call, in order: the one asked for, then the configured
     * fallbacks, ending on OpenRouter's free router. De-duplicated, order kept.
     *
     * @return list<string>
     */
    public function candidates(string $model): array
    {
        $chain = array_merge([$model], (array) config('ai.models.fallbacks', []), ['openrouter/free']);

        return array_values(array_unique(array_filter(array_map('trim', $chain))));
    }

    /**
     * The raw decoded response body (choices, usage, …) for callers that need more.
     *
     * FREE MODELS COME AND GO, so a call is not one model but a chain: the model asked
     * for, then `ai.models.fallbacks`, then the free router. A 404 (the slug was
     * withdrawn), a 402 (it needs credit), a 429 that survived the client's own retries
     * or a 503 (no endpoint up) moves to the next; anything else is that model's answer
     * and is thrown as before. A 400 that names max_tokens is retried on the same model
     * with half the budget, once — free models cap replies well below paid ones.
     */
    public function raw(array $messages, string $tier = 'mid', array $opts = []): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('OpenRouter is not configured (set OPENROUTER_API_KEY).');
        }

        $this->pace();
        $this->lastModel = null;

        // Per-call HTTP ceiling for callers that sit in front of a waiting user (the
        // Top-3 check-in gate). Pulled OUT of $opts before the payload is built so it is
        // never posted to OpenRouter as a body field.
        $timeout = (int) ($opts['timeout'] ?? config('ai.timeout', 30));
        unset($opts['timeout']);

        $payload = array_merge([
            'model' => $this->model($tier),
            'max_tokens' => (int) config('ai.max_tokens', 2000),
            'messages' => array_values($messages),
        ], $opts);

        // Never let a caller starve a reasoning model (see config('ai.min_max_tokens')).
        $floor = (int) config('ai.min_max_tokens', 512);
        if ((int) ($payload['max_tokens'] ?? 0) < $floor) {
            $payload['max_tokens'] = $floor;
        }

        $tried = [];
        foreach ($this->candidates((string) $payload['model']) as $model) {
            $payload['model'] = $model;
            $response = $this->request($timeout)->post($this->baseUrl.'/chat/completions', $payload);

            // One narrower retry when the model's reply cap is below what was asked.
            if ($response->status() === 400 && Str::contains(Str::lower((string) data_get($response->json(), 'error.message', '')), 'max_tokens') && (int) $payload['max_tokens'] > $floor) {
                $payload['max_tokens'] = max($floor, (int) floor(((int) $payload['max_tokens']) / 2));
                $response = $this->request($timeout)->post($this->baseUrl.'/chat/completions', $payload);
            }

            if ($response->status() === 401) {
                throw new RuntimeException('OpenRouter rejected the API key (401 — check OPENROUTER_API_KEY).');
            }

            if (in_array($response->status(), [402, 404, 429, 503], true)) {
                $tried[$model] = $response->status().' '.Str::limit((string) (data_get($response->json(), 'error.message') ?: trim((string) $response->body())), 120);

                continue;
            }

            if ($response->failed()) {
                // OpenRouter puts a human message in error.message (e.g. "Key limit exceeded",
                // "No endpoints found", a data-policy block). Surface it so the cause is obvious.
                $msg = (string) data_get($response->json(), 'error.message', '');
                $detail = $msg !== '' ? $msg : Str::limit(trim((string) $response->body()), 200);
                throw new RuntimeException("OpenRouter API error {$response->status()}: {$detail}");
            }

            $body = $response->json() ?? [];
            $this->lastModel = (string) (data_get($body, 'model') ?: $model);

            return $body;
        }

        throw new RuntimeException('No OpenRouter model could answer — tried '.collect($tried)->map(fn ($why, $m) => "{$m} ({$why})")->implode('; ').'.');
    }

    /** Resolve a tier to a concrete model id, falling back to the mid tier. */
    public function model(string $tier): string
    {
        return (string) (config("ai.models.{$tier}") ?? config('ai.models.mid') ?? 'anthropic/claude-3.5-sonnet');
    }

    private function request(?int $timeout = null)
    {
        $retries = max(1, (int) config('ai.rate_limit.max_retries', 3));
        $timeout = max(1, (int) ($timeout ?: config('ai.timeout', 30)));

        return Http::withToken($this->apiKey)
            ->withHeaders([
                // OpenRouter uses these purely for its own dashboard attribution.
                'HTTP-Referer' => (string) config('ai.referer'),
                'X-Title' => (string) config('ai.app_title'),
            ])
            ->acceptJson()
            ->timeout($timeout)
            ->retry($retries, 1500, function ($exception) {
                $status = method_exists($exception, 'response') && $exception->response
                    ? $exception->response->status() : null;

                return in_array($status, [429, 500, 502, 503, 504], true);
            }, throw: false);
    }

    /**
     * Space calls so the whole app stays under config('ai.rate_limit.per_minute').
     * Persisted in the cache so it holds across requests / queue workers. Skipped in tests.
     */
    protected function pace(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $perMinute = max(1, (int) config('ai.rate_limit.per_minute', 60));
        $minGapMs = (int) ceil(60000 / $perMinute);

        // FAIL-SOFT. Pacing is a courtesy to the provider, not a correctness rule,
        // and it must never be the thing that takes an AI feature down.
        //
        // CACHE_STORE is `database` in production, so this is a read AND a write
        // against Postgres on every single call. A cold start, an exhausted
        // connection pool or any transient blip threw straight out of here — before
        // a single byte went to OpenRouter — and every caller reported it as "the AI
        // is unavailable". The cause was in the database, the message blamed the
        // model, and the request never happened at all.
        //
        // Losing the throttle for one call is the cheaper failure by a wide margin:
        // the worst case is a 429, which the retry policy above already handles.
        try {
            $last = (int) Cache::get('ai:last_call_ms', 0);
            $nowMs = (int) (microtime(true) * 1000);
            $wait = $last + $minGapMs - $nowMs;
            if ($wait > 0) {
                usleep($wait * 1000);
                $nowMs += $wait;
            }
            Cache::put('ai:last_call_ms', $nowMs, 120);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Pull the first {...} block out of a reply in case the model wraps it in prose. */
    protected function extractJson(string $text): string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return $text;
        }

        return substr($text, $start, $end - $start + 1);
    }
}
