<?php

/**
 * Shared AI configuration — the single place every AI feature reads from.
 *
 * Two providers answer the same calls: the Claude API (ANTHROPIC_API_KEY) and OpenRouter
 * (OPENROUTER_API_KEY), an OpenAI-compatible gateway. Only the keys are secrets (kept in
 * the environment); everything here is non-secret and git-tracked.
 *
 * A feature asks for a TIER ('cheap' | 'mid' | 'strong') instead of hardcoding a model, so
 * cost/quality is tuned here (or via env) without touching code:
 *   - cheap  : classification / scoring
 *   - mid    : drafting, summaries
 *   - strong : reading documents, complex reasoning
 *
 * AI produces DRAFTS ONLY: a person confirms every result before it is saved (spec §7.17's
 * "structured order lines for confirmation", the packing-list review screen).
 */
return [

    // Master switch. When false (or no provider has a key) every feature falls back to
    // its manual path and the app works without AI.
    'enabled' => env('AI_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    | 'auto' (the default) picks the Claude API when ANTHROPIC_API_KEY is set and
    | OpenRouter otherwise. Force one with 'anthropic' or 'openrouter'.
    |
    | Every feature is written against OpenRouterClient's chat()/json()/model();
    | AnthropicClient answers the same calls through the official SDK, and the
    | container hands out whichever the provider says (AppServiceProvider).
    */
    'provider' => env('AI_PROVIDER', 'auto'),

    // The Claude API, directly. ANTHROPIC_API_KEY is the only secret.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL'),

        // One model for every tier by default; override per tier if needed
        // (e.g. ANTHROPIC_MODEL_CHEAP=claude-haiku-4-5).
        'models' => [
            'cheap' => env('ANTHROPIC_MODEL_CHEAP', 'claude-opus-5'),
            'mid' => env('ANTHROPIC_MODEL_MID', 'claude-opus-5'),
            'strong' => env('ANTHROPIC_MODEL_STRONG', 'claude-opus-5'),
        ],

        // How hard the model thinks per tier (output_config.effort).
        'effort' => [
            'cheap' => env('ANTHROPIC_EFFORT_CHEAP', 'low'),
            'mid' => env('ANTHROPIC_EFFORT_MID', 'medium'),
            'strong' => env('ANTHROPIC_EFFORT_STRONG', 'high'),
        ],

        // Thinking tokens count against max_tokens on Claude, so a small budget would
        // starve the model before it wrote a word. This floor is applied to every request.
        'min_max_tokens' => (int) env('ANTHROPIC_MIN_MAX_TOKENS', 4096),

        // Seconds. A strong-tier call over a long document can take a while.
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 2),
    ],

    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'api_key' => env('OPENROUTER_API_KEY'),

    // OpenRouter uses these two headers for its dashboard analytics (optional, non-secret).
    'referer' => env('OPENROUTER_REFERER', env('APP_URL', 'http://localhost')),
    'app_title' => env('OPENROUTER_TITLE', env('APP_NAME', 'ERP')),

    // Seconds for one OpenRouter reply. A timeout does not retry, so this is the
    // worst-case single wait.
    'timeout' => (int) env('AI_TIMEOUT', 60),

    // Default reply budget when a caller doesn't specify one.
    'max_tokens' => (int) env('AI_MAX_TOKENS', 2000),

    // Floor applied to EVERY request: reasoning models spend completion tokens on hidden
    // reasoning before emitting content, and with too small a budget return an empty reply.
    'min_max_tokens' => (int) env('AI_MIN_MAX_TOKENS', 512),

    // Per-tier OpenRouter model routing. Any OpenRouter model id is valid.
    // `openrouter/free` is OpenRouter's router over the day's free models. Free models
    // may log or train on what is sent — see each feature's 'data' below.
    'models' => [
        'cheap' => env('AI_MODEL_CHEAP', 'openrouter/free'),
        'mid' => env('AI_MODEL_MID', 'openrouter/free'),
        'strong' => env('AI_MODEL_STRONG', 'openrouter/free'),

        // Tried in order when the model asked for is gone (404), needs credit (402), is
        // rate-limited after the client's own retries (429) or has no endpoint up
        // (503). Comma-separated in the env. The router is always the last resort.
        'fallbacks' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_MODEL_FALLBACKS', 'openrouter/free'))))),
    ],

    // Keep the whole app under a sane call rate (paced in the client via the cache) and
    // retry transient upstream errors.
    'rate_limit' => [
        'per_minute' => (int) env('AI_RATE_PER_MINUTE', 60),
        // Total ATTEMPTS (not extra tries). 2 = one retry.
        'max_retries' => (int) env('AI_MAX_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-feature switches
    |--------------------------------------------------------------------------
    | Each sub-project registers its own features here; none ship yet.
    | Read via App\Services\Ai\AiFeature::enabled('key') — a key not listed is off.
    |
    |   'packing_list_draft' => ['enabled' => env('AI_F_PACKING_LIST', false), 'tier' => 'strong', 'data' => 'high'],
    |
    | 'tier'  → which model tier from 'models' above this feature uses.
    | 'data'  → sensitivity of what is sent to the provider:
    |     low    = aggregates / no personal or customer data
    |     medium = business text
    |     high   = customer documents, prices, contacts
    |   Keep 'high' features on a PAID model before enabling them in production.
    */
    'features' => [],
];
