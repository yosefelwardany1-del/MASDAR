<?php

namespace Tests\Unit;

use App\Support\AppKey;
use PHPUnit\Framework\TestCase;

/**
 * Render's `generateValue` makes a bare base64 string of 32 random bytes; Laravel reads a key
 * as base64 only behind "base64:". Read as it is, that 44-character string is no AES-256 key
 * and every encrypted cookie fails — the preview's first page was a 500 (20 Oct).
 */
class AppKeyTest extends TestCase
{
    public function test_a_bare_base64_key_of_32_bytes_is_read_as_base64(): void
    {
        $generated = base64_encode(random_bytes(32));

        $this->assertSame('base64:'.$generated, AppKey::normalise($generated));
    }

    public function test_any_other_key_is_left_as_it_is(): void
    {
        foreach (['base64:'.base64_encode(random_bytes(32)), str_repeat('a', 32), base64_encode(random_bytes(16)), '', null] as $key) {
            $this->assertSame($key, AppKey::normalise($key));
        }
    }

    public function test_the_config_reads_the_key_through_it(): void
    {
        $this->assertStringContainsString('AppKey::normalise(env(\'APP_KEY\'))', file_get_contents(__DIR__.'/../../config/app.php'));
    }
}
