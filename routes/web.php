<?php

use App\Http\Controllers\Auth\GoogleSsoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfilePhotoController;
use Illuminate\Support\Facades\Route;

// Sign-in
Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1')->name('login.post');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Sign in with Google: guest routes that 404 while SSO is switched off. A person with no
// account goes to the waiting page rather than getting a session.
Route::get('/auth/google', [GoogleSsoController::class, 'redirect'])->middleware('throttle:30,1')->name('sso.google');
Route::get('/auth/google/callback', [GoogleSsoController::class, 'callback'])->middleware('throttle:30,1')->name('sso.google.callback');
Route::get('/auth/pending', [GoogleSsoController::class, 'pending'])->name('sso.pending');

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

    // The signed-in user's own profile. The photo write routes act on the CALLER only (no
    // {user} segment); serving takes a {user} because the same face is shown to colleagues.
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::post('/profile/photo', [ProfilePhotoController::class, 'store'])->name('profile.photo.store');
    Route::delete('/profile/photo', [ProfilePhotoController::class, 'destroy'])->name('profile.photo.destroy');
    Route::get('/users/{user}/photo', [ProfilePhotoController::class, 'show'])->whereNumber('user')->name('profile.photo');

    // The signed-in user's own notifications: no screen permission, ownership only.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::put('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::put('notifications/{id}/read', [NotificationController::class, 'markRead'])->whereNumber('id')->name('notifications.read');
    Route::put('notifications/{id}/unread', [NotificationController::class, 'markUnread'])->whereNumber('id')->name('notifications.unread');
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->whereNumber('id')->name('notifications.destroy');
});
