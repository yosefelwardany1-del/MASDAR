<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AvatarComponentTest extends TestCase
{
    public function test_a_callers_style_is_merged_not_duplicated(): void
    {
        // Two style attributes on one element and the browser keeps only the first:
        // the logo placeholder lost its background that way.
        $html = Blade::render('<x-avatar name="Example Co" :size="40" style="border-radius:12px;" />');

        $this->assertSame(1, substr_count($html, 'style="'), $html);
        $this->assertStringContainsString('linear-gradient', $html);
        $this->assertStringContainsString('border-radius:12px;', $html);
    }
}
