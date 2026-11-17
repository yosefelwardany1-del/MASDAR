<?php

namespace App\Http\Controllers\Parties;

use App\Services\Parties\PartyKind;

/** The clients screen: parties that are clients (spec §5.1). Everything else is in PartyController. */
class ClientController extends PartyController
{
    protected function kind(): PartyKind
    {
        return PartyKind::Client;
    }
}
