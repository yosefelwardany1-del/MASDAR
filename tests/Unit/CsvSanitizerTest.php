<?php

namespace Tests\Unit;

use App\Support\IO\CsvSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Proves CSV / spreadsheet formula-injection neutralization (SECURITY_AUDIT H3).
 */
class CsvSanitizerTest extends TestCase
{
    public function test_dangerous_leading_characters_are_neutralized(): void
    {
        $this->assertSame("'=cmd|'/c calc'!A1", CsvSanitizer::cell("=cmd|'/c calc'!A1"));
        $this->assertSame("'+1+1", CsvSanitizer::cell('+1+1'));
        $this->assertSame("'-2+3", CsvSanitizer::cell('-2+3'));
        $this->assertSame("'@SUM(A1)", CsvSanitizer::cell('@SUM(A1)'));
        $this->assertSame("'\tTabbed", CsvSanitizer::cell("\tTabbed"));
        $this->assertSame("'\rCarriage", CsvSanitizer::cell("\rCarriage"));
    }

    public function test_safe_values_are_unchanged(): void
    {
        $this->assertSame('Acme Corp', CsvSanitizer::cell('Acme Corp'));
        $this->assertSame('123.45', CsvSanitizer::cell('123.45'));
        $this->assertSame('', CsvSanitizer::cell(''));
        $this->assertSame(42, CsvSanitizer::cell(42));        // non-string passthrough
        $this->assertNull(CsvSanitizer::cell(null));
    }

    public function test_row_helper_sanitizes_every_cell(): void
    {
        $this->assertSame(['Name', "'=EVIL()", 'ok'], CsvSanitizer::row(['Name', '=EVIL()', 'ok']));
    }
}
