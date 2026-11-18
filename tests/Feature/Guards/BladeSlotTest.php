<?php

namespace Tests\Feature\Guards;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Blade compiles </x-slot> into @endslot; a directive written straight after it ("@endslot@endif")
 * is not seen as a directive, and the view fails to compile. A line break keeps them apart.
 */
class BladeSlotTest extends TestCase
{
    public function test_no_directive_follows_a_closing_slot_on_the_same_spot(): void
    {
        $hits = [];
        foreach ((new Finder)->files()->name('*.blade.php')->in(resource_path('views')) as $file) {
            if (preg_match('/<\/x-slot(?::[\w-]+)?>@/', $file->getContents())) {
                $hits[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $hits, 'Put a line break between </x-slot> and the directive after it');
    }
}
