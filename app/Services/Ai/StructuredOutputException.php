<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/**
 * Why a structured() call produced nothing a caller may use. A RuntimeException, so every
 * existing "AI failed, carry on" catch still catches it; the REASON is a short code a
 * caller can store and show (config('ats.omni.failure_labels') for the Omni-Screener).
 */
class StructuredOutputException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';

    public const HTTP = 'http';

    public const REFUSED = 'refused';

    public const TRUNCATED = 'truncated';

    public const INVALID_JSON = 'invalid_json';

    public const WRONG_MODEL = 'wrong_model';

    /** What the call used when a reply came back but could not be used (Phase 4: spend). */
    public ?StructuredUsage $usage = null;

    public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function withUsage(?StructuredUsage $usage): static
    {
        $this->usage = $usage;

        return $this;
    }
}
