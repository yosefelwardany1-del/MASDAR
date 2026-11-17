<?php

namespace App\Http\Controllers\Parties;

use App\Services\Parties\PartyKind;

/** The suppliers screen: parties that are suppliers (spec §5.1). Everything else is in PartyController. */
class SupplierController extends PartyController
{
    protected function kind(): PartyKind
    {
        return PartyKind::Supplier;
    }
}
