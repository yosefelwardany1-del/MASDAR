<?php

namespace App\Services\Ai;

/**
 * The single gate every AI feature asks before doing anything.
 *
 * A feature is live only when: AI is globally on, an API key exists, AND that feature's
 * own switch in config('ai.features') is on. Everything else in the app is written to
 * fall back to its existing deterministic behaviour when this returns false, so turning
 * AI off (or losing the key) can never break a page, a document or a save.
 */
class AiFeature
{
    public static function enabled(string $feature): bool
    {
        if (! config('ai.enabled', true) || ! AiProvider::hasKey()) {
            return false;
        }

        return (bool) config("ai.features.{$feature}.enabled", false);
    }

    /** Model tier configured for a feature ('cheap'|'mid'|'strong'). */
    public static function tier(string $feature): string
    {
        return (string) config("ai.features.{$feature}.tier", 'mid');
    }

    /** Declared sensitivity of the data this feature sends out ('low'|'medium'|'high'). */
    public static function sensitivity(string $feature): string
    {
        return (string) config("ai.features.{$feature}.data", 'high');
    }
}
