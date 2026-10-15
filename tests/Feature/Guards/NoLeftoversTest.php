<?php

namespace Tests\Feature\Guards;

use App\Security\Screens;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Nothing of the source ERP's company layer, business modules or brand survives, and no
 * permission bypass creeps back in.
 */
class NoLeftoversTest extends TestCase
{
    use RefreshDatabase;

    private const DIRS = ['app', 'config', 'routes', 'resources', 'lang', 'database', 'tests'];

    /** Removed code, matched case-sensitively. */
    private const REMOVED = '/CompanyScope|BelongsToCompany|CompanyContext|Employee|Village|Foundry|App\\\\Ats|Convo|Gate::before/';

    /** @return iterable<string, string> path => contents */
    private function sources(): iterable
    {
        foreach (self::DIRS as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if ($path === __FILE__ || ! preg_match('/\.(php|js|css|json|md)$/', $path)) {
                    continue;
                }
                yield substr($path, strlen(base_path()) + 1) => (string) file_get_contents($path);
            }
        }
    }

    public function test_no_removed_code_is_referenced(): void
    {
        $hits = [];
        foreach ($this->sources() as $path => $contents) {
            if (preg_match_all(self::REMOVED, $contents, $m)) {
                $hits[] = $path.': '.implode(', ', array_unique($m[0]));
            }
            if (stripos($contents, 'insharo') !== false) {
                $hits[] = $path.': insharo';
            }
        }

        $this->assertSame([], $hits);
    }

    public function test_every_screen_route_exists(): void
    {
        $missing = [];
        foreach (config('screens') as $key => $screen) {
            if (! Route::has($screen['route'])) {
                $missing[] = "{$key} → {$screen['route']}";
            }
        }

        $this->assertSame([], $missing, 'a sidebar entry with no route would render as a dead link');
    }

    public function test_every_screen_permission_exists_once_seeded(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            Screens::allPermissions(),
            Permission::pluck('name')->all(),
        );
    }
}
