<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleSsoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportExportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserSsoIdentityController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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
     * $except drops actions the resource must not have (e.g. users are never deleted).
     */
    $pres = function (string $uri, string $controller, string $screen, array $except = [], ?string $param = null) {
        $bind = fn ($registration) => $param ? $registration->parameters([$uri => $param]) : $registration;
        $only = fn (array $actions) => array_values(array_diff($actions, $except));

        Route::resource($uri, $controller)->only($only(['index']))->middleware("can:{$screen}.view");
        Route::resource($uri, $controller)->only($only(['create', 'store']))->middleware("can:{$screen}.edit");
        $bind(Route::resource($uri, $controller)->only($only(['show'])))->whereNumber(Str::singular($uri))->middleware("can:{$screen}.view");
        $bind(Route::resource($uri, $controller)->only($only(['edit', 'update', 'destroy'])))->whereNumber(Str::singular($uri))->middleware("can:{$screen}.edit");
    };

    // Users are deactivated, never deleted: every row is attributed to one (spec I4).
    $pres('users', UserController::class, 'users', except: ['destroy']);
    Route::post('users/{user}/sso-identities', [UserSsoIdentityController::class, 'store'])
        ->whereNumber('user')->middleware('can:users.edit')->name('users.sso-identities.store');
    Route::delete('users/{user}/sso-identities/{identity}', [UserSsoIdentityController::class, 'destroy'])
        ->whereNumber(['user', 'identity'])->middleware('can:users.edit')->name('users.sso-identities.destroy');

    // The ten roles are fixed (spec §10.2): their matrix is edited, they are never created or deleted.
    $pres('roles', RoleController::class, 'roles', except: ['create', 'store', 'destroy']);

    // Company settings (one row) and its logo, which any signed-in user may see.
    Route::get('settings', [SettingsController::class, 'edit'])->middleware('can:settings.view')->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->middleware('can:settings.edit')->name('settings.update');
    Route::post('settings/logo', [SettingsController::class, 'storeLogo'])->middleware('can:settings.edit')->name('settings.logo.store');
    Route::delete('settings/logo', [SettingsController::class, 'destroyLogo'])->middleware('can:settings.edit')->name('settings.logo.destroy');
    Route::get('settings/logo', [SettingsController::class, 'showLogo'])->name('settings.logo');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit_log.view')->name('audit-logs.index');

    // Bulk import / export, one controller for every registered resource. Two gates: the
    // import_export screen here, and the resource's own screen inside the controller.
    Route::get('import-export', [ImportExportController::class, 'index'])->middleware('can:import_export.view')->name('import-export.index');
    Route::get('io/{resource}/template', [ImportExportController::class, 'template'])->middleware('can:import_export.view')->name('io.template');
    Route::get('io/{resource}/export', [ImportExportController::class, 'export'])->middleware('can:import_export.view')->name('io.export');
    Route::post('io/{resource}/preview', [ImportExportController::class, 'preview'])->middleware('can:import_export.edit')->name('io.preview');
    Route::post('io/{resource}/import', [ImportExportController::class, 'import'])->middleware('can:import_export.edit')->name('io.import');
    Route::get('io/runs/{run}', [ImportExportController::class, 'status'])->whereNumber('run')->middleware('can:import_export.view')->name('io.run-status');

    // Attachments for every registered attachable; the parent's screen decides access.
    Route::post('attachments/{type}/{id}', [AttachmentController::class, 'store'])->whereNumber('id')->name('attachments.store');
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])->whereNumber('attachment')->name('attachments.download');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('attachment')->name('attachments.destroy');

    // MASDAR's modules: one route file each (routes/modules/*.php), each gated by its own
    // screen. They are required here so $pres is in scope.
    foreach (glob(__DIR__.'/modules/*.php') as $moduleRoutes) {
        require $moduleRoutes;
    }

    // Sign-in only: the home page is where a user with no roles lands.
    Route::get('/', DashboardController::class)->name('dashboard');

    // The signed-in user's own profile. The photo write routes act on the CALLER only (no
    // {user} segment); serving takes a {user} because the same face is shown to colleagues.
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::put('/profile/locale', [ProfileController::class, 'updateLocale'])->name('profile.locale');
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
