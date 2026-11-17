<?php

namespace App\Http\Controllers\Parties;

use App\Services\Parties\PartyKind;

/** The shipping_companies screen: parties that are shipping companies (spec §5.1). Everything else is in PartyController. */
class ShippingCompanyController extends PartyController
{
    protected function kind(): PartyKind
    {
        return PartyKind::Shipping;
    }
}
