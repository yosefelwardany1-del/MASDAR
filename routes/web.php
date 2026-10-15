<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
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

    // Sign-in only: the home page is where a user with no roles lands.
    Route::get('/', DashboardController::class)->name('dashboard');

    // The signed-in user's own notifications: no screen permission, ownership only.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::put('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::put('notifications/{id}/read', [NotificationController::class, 'markRead'])->whereNumber('id')->name('notifications.read');
    Route::put('notifications/{id}/unread', [NotificationController::class, 'markUnread'])->whereNumber('id')->name('notifications.unread');
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->whereNumber('id')->name('notifications.destroy');
});
