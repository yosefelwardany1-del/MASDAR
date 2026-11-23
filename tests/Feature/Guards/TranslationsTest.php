<?php

namespace Tests\Feature\Guards;

use App\Http\Controllers\Configuration\ConfigurationController;
use App\Models\Lookup;
use App\Security\Roles;
use App\Services\Accounting\PostingService;
use Illuminate\Support\Arr;
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

    /** Labels kept as English in code and shown through __($label): screens, lists, roles, posting events, list fields. */
    public function test_labels_kept_in_code_are_translated(): void
    {
        $labels = array_merge(
            array_column(config('screens'), 'label'),
            array_values(Lookup::LISTS),
            array_values((new \ReflectionClassConstant(Roles::class, 'LABELS'))->getValue()),
            array_values(PostingService::EVENT_LABELS),
            array_merge(...array_values(array_map('array_values', (new \ReflectionClassConstant(ConfigurationController::class, 'META'))->getValue()))),
        );
        foreach (self::LOCALES as $locale) {
            $dictionary = $this->dictionary($locale);
            $this->assertSame([], array_values(array_unique(array_filter($labels, fn ($l) => ! array_key_exists($l, $dictionary)))), "Translate these in lang/{$locale}.json");
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

    /**
     * B31: a key made by joining a code to a prefix (__('import.'.$status)) has no entry in the
     * JSON dictionaries, so it shows as the bare key — in English too. Give each value its own
     * English key instead.
     */
    public function test_no_key_is_built_from_a_prefix_and_a_code(): void
    {
        $found = [];
        $finder = (new Finder)->files()->name('*.php')->in([app_path(), resource_path('views'), config_path(), base_path('routes')]);
        foreach ($finder as $file) {
            if (preg_match_all('/(?:\b__|\btrans_choice|@lang|\bText::of|\bText::choice)\(\s*\'[^\']*\'\s*\./', $file->getContents(), $m)) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname()).': '.implode(', ', $m[0]);
            }
        }
        $this->assertSame([], $found);
    }

    /**
     * B31: Laravel's own messages (validation, sign-in, password reset) exist in Arabic and
     * Chinese, keeping their placeholders. They leave out :attribute — the English field name —
     * as each is shown next to its field.
     */
    public function test_laravels_own_messages_are_translated(): void
    {
        $english = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');
        foreach (['validation', 'auth', 'passwords'] as $group) {
            $source = Arr::except(require "{$english}/{$group}.php", ['custom', 'attributes']);
            foreach (self::LOCALES as $locale) {
                $path = lang_path("{$locale}/{$group}.php");
                $this->assertFileExists($path);
                $translated = Arr::dot(require $path);
                $broken = [];
                foreach (Arr::dot($source) as $key => $text) {
                    $expected = array_values(array_diff($this->placeholders($text), [':attribute']));
                    if (! isset($translated[$key]) || trim($translated[$key]) === '' || $this->placeholders($translated[$key]) !== $expected) {
                        $broken[] = $key;
                    }
                }
                $this->assertSame([], $broken, "lang/{$locale}/{$group}.php: missing, empty, or a placeholder lost or added");
            }
        }

        app()->setLocale('ar');
        $this->assertSame('هذا الحقل مطلوب.', validator(['x' => ''], ['x' => 'required'])->errors()->first('x'));
        app()->setLocale('zh_CN');
        $this->assertSame('此项为必填项。', validator(['x' => ''], ['x' => 'required'])->errors()->first('x'));
    }

    public function test_the_languages_hold_the_same_strings(): void
    {
        $ar = array_keys($this->dictionary('ar'));
        $zh = array_keys($this->dictionary('zh_CN'));
        $this->assertSame([], array_values(array_diff($ar, $zh)), 'in Arabic, not in Chinese');
        $this->assertSame([], array_values(array_diff($zh, $ar)), 'in Chinese, not in Arabic');
    }

    /** @return array<string, string> each literal string passed to __(), trans_choice(), @lang or Text (B31), with a file using it */
    private function keysInCode(): array
    {
        $finder = (new Finder)->files()->name('*.php')
            ->in([app_path(), resource_path('views'), config_path(), base_path('routes')]);
        $keys = [];
        foreach ($finder as $file) {
            // A Blade comment may show an example call; it is not a string the reader sees.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
            preg_match_all('/(?:\b__|\btrans_choice|@lang|\bText::of|\bText::choice)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $source, $matches);
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
