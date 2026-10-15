<?php

namespace Tests\Concerns;

use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Seeds the ten roles and their screen permissions inside each test's transaction.
 *
 * Not RefreshDatabase's `$seed` property: that seeds only during the one migrate:fresh a
 * test process runs, so it silently does nothing whenever another class migrated first.
 * Laravel calls setUp{TraitName}() after RefreshDatabase has opened the transaction.
 */
trait SeedsRoles
{
    protected function setUpSeedsRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }
}
