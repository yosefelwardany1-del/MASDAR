<?php

namespace Tests\Concerns;

use Database\Seeders\MasdarReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Seeds the roles AND MASDAR's reference data (lists, order steps, chart of accounts, posting
 * rules) inside each test's transaction. Use instead of SeedsRoles in business-module tests.
 */
trait SeedsReference
{
    protected function setUpSeedsReference(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasdarReferenceSeeder::class);
    }
}
