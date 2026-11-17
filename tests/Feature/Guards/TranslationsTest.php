<?php

namespace Tests\Feature\Guards;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every user picks Arabic, English or Chinese, so every UI string written in English in the code
 * has an Arabic and a Chinese translation, and each translation keeps the string's placeholders.
 */
class TranslationsTest extends TestCase
{
    private const LOCALES = ['ar', 'zh_CN'];

    public function test_every_ui_string_has_an_arabic_and_a_chinese_translation(): void
    {
        $keys = $this->keysInCode();
        $this->assertGreaterThan(1000, count($keys), 'the scan finds the UI strings');

        foreach (self::LOCALES as $locale) {
            $dictionary = $this->dictionary($locale);
            $missing = [];
            foreach ($keys as $key => $file) {
                if (! array_key_exists($key, $dictionary)) {
                    $missing[] = $key.'  ('.$file.')';
                }
            }
            $this->assertSame([], $missing, "Translate these in lang/{$locale}.json");
        }
    }

    public function test_each_translation_keeps_its_placeholders(): void
    {
        foreach (self::LOCALES as $locale) {
            $broken = [];
            foreach ($this->dictionary($locale) as $key => $text) {
                if (trim($text) === '' || $this->placeholders($key) !== $this->placeholders($text)) {
                    $broken[] = $key;
                }
            }
            $this->assertSame([], $broken, "lang/{$locale}.json: empty, or a placeholder lost or added");
        }
    }

    public function test_the_languages_hold_the_same_strings(): void
    {
        $ar = array_keys($this->dictionary('ar'));
        $zh = array_keys($this->dictionary('zh_CN'));
        $this->assertSame([], array_values(array_diff($ar, $zh)), 'in Arabic, not in Chinese');
        $this->assertSame([], array_values(array_diff($zh, $ar)), 'in Chinese, not in Arabic');
    }

    /** @return array<string, string> each literal string passed to __(), trans_choice() or @lang, with a file using it */
    private function keysInCode(): array
    {
        $finder = (new Finder)->files()->name('*.php')
            ->in([app_path(), resource_path('views'), config_path(), base_path('routes')]);
        $keys = [];
        foreach ($finder as $file) {
            // A Blade comment may show an example call; it is not a string the reader sees.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
            preg_match_all('/(?:\b__|\btrans_choice|@lang)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $source, $matches);
            foreach ($matches[1] as $literal) {
                $key = stripcslashes(substr($literal, 1, -1));
                // Keys of the PHP files (validation.required) and prefixes joined to a code ('audit.'.$action).
                if (preg_match('/^[a-z_]+\.[A-Za-z0-9_.:\-]*$/', $key)) {
                    continue;
                }
                $keys[$key] ??= str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        return $keys;
    }

    /** @return array<string, string> */
    private function dictionary(string $locale): array
    {
        return json_decode((string) file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[A-Za-z_]+/', $text, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }
}
