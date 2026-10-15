<?php

namespace App\Services\Ai;

/**
 * What one structured() call used, whether or not its reply was usable: the model that
 * answered, the tokens, OpenRouter's own cost figure when it reported one (US dollars), and
 * the provider's id for the call. A reply that came back and was then refused — the wrong
 * model, cut off, not JSON — still cost money, so a StructuredOutputException carries this
 * too (docs/OMNI_SCREENER.md, Phase 4).
 */
final class StructuredUsage
{
    public function __construct(
        public readonly ?string $model = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?float $cost = null,
        public readonly ?string $requestId = null,
    ) {}

    /** Did a reply come back at all? */
    public function isEmpty(): bool
    {
        return $this->inputTokens === null && $this->outputTokens === null && $this->cost === null;
    }
}
