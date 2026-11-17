<?php

use App\Http\Controllers\Parties\ClientController;
use App\Http\Controllers\Parties\ShippingCompanyController;
use App\Http\Controllers\Parties\SupplierController;
use App\Services\Parties\PartyKind;
use Illuminate\Support\Facades\Route;

// Clients, suppliers and shipping companies (spec §5.1): one party registry on three screens,
// each gated by its own screen. Parties are deactivated, never deleted. Required inside the
// auth group of routes/web.php, where $pres is in scope.
foreach ([
    [PartyKind::Client, ClientController::class],
    [PartyKind::Supplier, SupplierController::class],
    [PartyKind::Shipping, ShippingCompanyController::class],
] as [$kind, $controller]) {
    $uri = $kind->routePrefix();
    $screen = $kind->screen();

    $pres($uri, $controller, $screen, except: ['destroy']);

    Route::post("{$uri}/{party}/deactivate", [$controller, 'deactivate'])->whereNumber('party')->middleware("can:{$screen}.edit")->name("{$uri}.deactivate");
    Route::post("{$uri}/{party}/reactivate", [$controller, 'reactivate'])->whereNumber('party')->middleware("can:{$screen}.edit")->name("{$uri}.reactivate");
    Route::post("{$uri}/{party}/contacts", [$controller, 'storeContact'])->whereNumber('party')->middleware("can:{$screen}.edit")->name("{$uri}.contacts.store");
    Route::put("{$uri}/{party}/contacts/{contact}", [$controller, 'updateContact'])->whereNumber(['party', 'contact'])->middleware("can:{$screen}.edit")->name("{$uri}.contacts.update");
    Route::delete("{$uri}/{party}/contacts/{contact}", [$controller, 'destroyContact'])->whereNumber(['party', 'contact'])->middleware("can:{$screen}.edit")->name("{$uri}.contacts.destroy");
}
unset($kind, $controller, $uri, $screen);
