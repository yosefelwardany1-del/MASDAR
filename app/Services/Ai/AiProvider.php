<?php

namespace App\Services\Ai;

/**
 * Which API is behind the AI features right now.
 *
 * One question, answered in one place, because three things ask it: the container
 * (which client class to hand out), AiFeature (is any key present at all) and the
 * ai:ping command (what to say it is testing).
 */
final class AiProvider
{
    public const ANTHROPIC = 'anthropic';

    public const OPENROUTER = 'openrouter';

    /** 'anthropic' or 'openrouter'. */
    public static function resolve(): string
    {
        $forced = strtolower(trim((string) config('ai.provider', 'auto')));

        if ($forced === self::ANTHROPIC || $forced === self::OPENROUTER) {
            return $forced;
        }

        // auto: the Claude API the moment its key exists, OpenRouter otherwise.
        return filled(config('ai.anthropic.api_key')) ? self::ANTHROPIC : self::OPENROUTER;
    }

    /** Is there a key for whichever provider is selected? */
    public static function hasKey(): bool
    {
        return self::resolve() === self::ANTHROPIC
            ? filled(config('ai.anthropic.api_key'))
            : filled(config('ai.api_key'));
    }

    /** The provider's name as a person would say it. */
    public static function label(): string
    {
        return self::resolve() === self::ANTHROPIC ? 'the Claude API' : 'OpenRouter';
    }
}
