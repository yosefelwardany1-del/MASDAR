<?php

namespace App\Support;

use App\Models\Lookup;

/**
 * B31: text the system stores for a person to read later — a notification — kept as its English
 * key and parameters, and put into words in the reader's language when it is shown. A stored
 * sentence would stay in the language of whoever triggered it.
 *
 * A parameter may itself be a Text: a label (a status), an entry of an editable list (a leave
 * type, named per language on Lists & options), or a person's own words, shown as written. A
 * text can be a count (trans_choice), lower-cased (a label inside a sentence), or several texts
 * joined. Keys passed to of() and choice() are literal English strings, checked by TranslationsTest.
 */
final class Text implements \JsonSerializable
{
    private const KEY = 'key';

    private const PLAIN = 'plain';

    private const LOOKUP = 'lookup';

    private const JOIN = 'join';

    /**
     * @param  array<string, scalar|self|null>  $params
     * @param  list<self>  $parts
     */
    private function __construct(
        private readonly string $kind,
        private readonly string $value,
        private readonly array $params = [],
        private readonly ?int $count = null,
        private readonly bool $lower = false,
        private readonly array $parts = [],
    ) {}

    /** @param array<string, scalar|self|null> $params */
    public static function of(string $key, array $params = []): self
    {
        return new self(self::KEY, $key, $params);
    }

    /** @param array<string, scalar|self|null> $params */
    public static function choice(string $key, int $count, array $params = []): self
    {
        return new self(self::KEY, $key, $params, $count);
    }

    /** Words that are not a key — a person's own note — shown as written. */
    public static function plain(string $words): self
    {
        return new self(self::PLAIN, $words);
    }

    /** An entry of an editable list (Lookup), in the reader's language when it has one. */
    public static function lookup(string $list, string $code): self
    {
        return new self(self::LOOKUP, $code, ['list' => $list]);
    }

    /** @param list<self> $parts */
    public static function join(array $parts, string $glue = ' '): self
    {
        return new self(self::JOIN, $glue, [], null, false, array_values($parts));
    }

    /** The same text in lower case, for a label inside a sentence ("your annual leave"). */
    public function lower(): self
    {
        return new self($this->kind, $this->value, $this->params, $this->count, true, $this->parts);
    }

    /** The words in $locale (the current one when null). */
    public function render(?string $locale = null): string
    {
        $words = match ($this->kind) {
            self::PLAIN => $this->value,
            self::JOIN => implode($this->value, array_map(fn (self $p) => $p->render($locale), $this->parts)),
            self::LOOKUP => Lookup::query()->where('list', $this->params['list'])->where('code', $this->value)->first()?->displayName($locale) ?? $this->value,
            default => $this->count === null
                ? __($this->value, $this->renderedParams($locale), $locale)
                : trans_choice($this->value, $this->count, $this->renderedParams($locale), $locale),
        };

        return $this->lower ? mb_strtolower($words) : $words;
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'kind' => $this->kind === self::KEY ? null : $this->kind,
            'value' => $this->value,
            'params' => array_map(fn ($v) => $v instanceof self ? ['text' => $v->jsonSerialize()] : $v, $this->params) ?: null,
            'count' => $this->count,
            'lower' => $this->lower ?: null,
            'parts' => array_map(fn (self $p) => $p->jsonSerialize(), $this->parts) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $data as stored by jsonSerialize() */
    public static function fromArray(array $data): self
    {
        $kind = in_array($data['kind'] ?? null, [self::PLAIN, self::LOOKUP, self::JOIN], true) ? $data['kind'] : self::KEY;
        $params = array_map(fn ($v) => is_array($v) && isset($v['text']) ? self::fromArray((array) $v['text']) : $v, (array) ($data['params'] ?? []));

        return new self($kind, (string) ($data['value'] ?? ''), $params, isset($data['count']) ? (int) $data['count'] : null,
            (bool) ($data['lower'] ?? false), array_map(fn ($p) => self::fromArray((array) $p), (array) ($data['parts'] ?? [])));
    }

    /** @return array<string, string> */
    private function renderedParams(?string $locale): array
    {
        return array_map(fn ($v) => $v instanceof self ? $v->render($locale) : (string) $v, $this->params);
    }
}
