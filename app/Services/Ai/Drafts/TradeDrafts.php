<?php

namespace App\Services\Ai\Drafts;

use App\Services\Ai\AiFeature;
use App\Services\Ai\OpenRouterClient;
use App\Services\Ai\StructuredOutputException;

/**
 * AI drafts for the trade (spec §5.8, §8): only drafts — a person reviews each and saves what
 * they keep through the normal writers. Each feature is off unless an AI key is set and the
 * feature is on in config/ai.php.
 *
 *  - quotation_from_message: a client's message (email, WhatsApp) read into quotation lines;
 *  - translate_to_chinese: a product's name and description in Chinese, for the factories.
 */
class TradeDrafts
{
    public function __construct(private OpenRouterClient $ai) {}

    /**
     * @param  array<string, string>  $units  code => name, the units the lines may use
     * @return list<array{description: string, quantity: ?string, unit: ?string, target_price: ?string, note: ?string}>
     *
     * @throws StructuredOutputException
     */
    public function quotationLines(string $message, array $units): array
    {
        $this->assertEnabled('quotation_from_message');
        $reply = $this->ai->structured(
            "You read a buyer's message to MASDAR, a Hong Kong sourcing company buying in Yiwu, China, and list each product the buyer asks a price for. "
            .'Keep the buyer\'s own words for each item, in English; add size, material, colour or packing when the message gives them. '
            .'Give the quantity only when the buyer states one, as a plain number. Choose the unit from this list of codes only: '.implode(', ', array_keys($units)).'. '
            .'Give a target price only when the buyer names one. Never invent an item, a quantity or a price.',
            "The buyer's message:\n\n".mb_substr($message, 0, 8000),
            [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['lines'],
                'properties' => ['lines' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['description', 'quantity', 'unit', 'target_price', 'note'],
                    'properties' => [
                        'description' => ['type' => 'string'],
                        'quantity' => ['type' => ['number', 'null']],
                        'unit' => ['type' => ['string', 'null']],
                        'target_price' => ['type' => ['number', 'null']],
                        'note' => ['type' => ['string', 'null']],
                    ],
                ]]],
            ],
            'quotation_lines',
            ['max_tokens' => 8000],
        );

        $lines = [];
        foreach ((array) ($reply->data['lines'] ?? []) as $line) {
            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $lines[] = [
                'description' => mb_substr($description, 0, 2000),
                'quantity' => is_numeric($line['quantity'] ?? null) && $line['quantity'] > 0 ? (string) $line['quantity'] : null,
                'unit' => isset($units[$line['unit'] ?? '']) ? (string) $line['unit'] : null,
                'target_price' => is_numeric($line['target_price'] ?? null) && $line['target_price'] >= 0 ? (string) $line['target_price'] : null,
                'note' => filled($line['note'] ?? null) ? mb_substr((string) $line['note'], 0, 500) : null,
            ];
        }

        return array_slice($lines, 0, 50);
    }

    /**
     * @return array{name_local: string, description: string}
     *
     * @throws StructuredOutputException
     */
    public function chinese(string $name, ?string $description): array
    {
        $this->assertEnabled('translate_to_chinese');
        $reply = $this->ai->structured(
            'You translate product names and specifications from English into the simplified Chinese a Yiwu factory uses on its quotations and purchase contracts. '
            .'Keep numbers, sizes, materials and model codes exact. Answer with the Chinese only.',
            "Product name: {$name}\nSpecification: ".($description ?: '—'),
            [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['name_local', 'description'],
                'properties' => ['name_local' => ['type' => 'string'], 'description' => ['type' => 'string']],
            ],
            'product_in_chinese',
            ['max_tokens' => 2000],
        );

        return [
            'name_local' => mb_substr(trim((string) ($reply->data['name_local'] ?? '')), 0, 255),
            'description' => mb_substr(trim((string) ($reply->data['description'] ?? '')), 0, 5000),
        ];
    }

    public static function enabled(string $feature): bool
    {
        return AiFeature::enabled($feature);
    }

    private function assertEnabled(string $feature): void
    {
        if (! AiFeature::enabled($feature)) {
            throw new StructuredOutputException(StructuredOutputException::UNAVAILABLE, 'This AI feature is off (no AI key, or switched off in config/ai.php).');
        }
    }
}
