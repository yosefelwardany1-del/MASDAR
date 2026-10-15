<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {

    /**
     * A resource gated by its screen (config/screens.php): reading (index, show) needs
     * `{screen}.view`; writing (create, store, edit, update, destroy) needs `{screen}.edit`.
     * `create` registers before the `{model}` routes so it is never captured by show.
     */
    $pres = function (string $uri, string $controller, string $screen, ?string $param = null) {
        $bind = fn ($registration) => $param ? $registration->parameters([$uri => $param]) : $registration;

        Route::resource($uri, $controller)->only(['index'])->middleware("can:{$screen}.view");
        Route::resource($uri, $controller)->only(['create', 'store'])->middleware("can:{$screen}.edit");
        $bind(Route::resource($uri, $controller)->only(['show']))->middleware("can:{$screen}.view");
        $bind(Route::resource($uri, $controller)->only(['edit', 'update', 'destroy']))->middleware("can:{$screen}.edit");
    };
});
