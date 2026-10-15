<?php

namespace App\Services\Ai;

/**
 * A reply that came back in the JSON schema it was asked for: the decoded object, the
 * model that ACTUALLY answered (which a caller recording provenance must store, not the
 * one it asked for), the token counts, and — when the provider reported them — what the
 * call cost (US dollars) and the provider's id for it.
 */
final class StructuredReply
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly string $model,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $finishReason = null,
        public readonly ?float $cost = null,
        public readonly ?string $requestId = null,
    ) {}

    public function usage(): StructuredUsage
    {
        return new StructuredUsage($this->model, $this->inputTokens, $this->outputTokens, $this->cost, $this->requestId);
    }
}
