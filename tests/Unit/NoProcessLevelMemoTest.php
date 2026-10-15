<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No application class may memoise in a function-level `static`.
 *
 * The pattern caused two real bugs in the codebase this platform was harvested from, both
 * keyed by an id.
 *
 * A PHP `static` inside a method lives for the whole PROCESS, not the request. Under
 * PHP-FPM that looks request-scoped by accident; under a queue worker, an artisan command
 * or Octane it answers for whoever held that key last. In the test suite it is worse
 * still: RefreshDatabase restarts auto-increment at 1 for every test, so an id is handed
 * to a different person within seconds, and the resulting failure is order-dependent —
 * green alone, red in company, and blamed on the wrong test.
 *
 * Both comments claimed "cached per request". The comment is not the mechanism.
 *
 * THE SANCTIONED ALTERNATIVE is a memo on an instance of a `scoped` binding: Laravel
 * clears scoped instances at the end of a request and between queued jobs, which is
 * exactly the lifetime a memo is supposed to have.
 *
 * If a genuinely process-safe static is ever needed — a compiled regex, a lookup table
 * that depends on nothing — it will trip this test, and that is the right moment to say
 * so in a comment and add it to the exemptions below rather than to discover it in a
 * flaky CI run.
 */
class NoProcessLevelMemoTest extends TestCase
{
    /**
     * Files allowed a function-level static, with the reason. Empty on purpose: there is
     * currently no legitimate case in app/, and adding one should be a deliberate act.
     *
     * @var array<string,string>
     */
    private const EXEMPT = [];

    public function test_no_class_in_app_memoises_in_a_function_level_static(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(dirname(__DIR__, 2).'/app') as $file) {
            $relative = 'app'.explode('/app', $file, 2)[1];

            if (array_key_exists($relative, self::EXEMPT)) {
                continue;
            }

            foreach (file($file) as $number => $line) {
                // `static $x = …` or `static $x;` at statement level inside a method.
                // Deliberately does NOT match `public static function`, `static::`,
                // `new static`, or a `static` closure — only a static VARIABLE.
                if (preg_match('/^\s*static\s+\$\w+\s*(=|;)/', $line)) {
                    $offenders[] = $relative.':'.($number + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A function-level static outlives the request — see this test's docblock.\n"
            ."Memoise on an instance of a `scoped` binding instead.\n\n"
            .implode("\n", $offenders)."\n",
        );
    }

    /** @return iterable<string> */
    private function phpFilesIn(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
