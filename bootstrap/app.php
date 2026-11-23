<?php

use App\Http\Middleware\EnsureAccountCanSignIn;
use App\Http\Middleware\EnsurePortalUser;
use App\Http\Middleware\EnsureStaffUser;
use App\Http\Middleware\ProfileRequest;
use App\Http\Middleware\SetUserLocale;
use App\Support\Database\Refusal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // LISTENER DISCOVERY OFF: every listener is registered by hand in AppServiceProvider,
    // where its order and reason are written down. A listener that is not registered there
    // never runs. See CLAUDE.md (standing rule: no hidden model events).
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware) {
        // First in the global stack so it times the whole request: every response carries
        // a Server-Timing header, and anything slower than PROFILE_SLOW_MS is logged.
        $middleware->prepend(ProfileRequest::class);

        // Trust the hosting proxy so X-Forwarded-Proto (https) is honoured.
        $middleware->trustProxies(at: '*');

        // A paused account is signed out on its next request, however it got in: a
        // remembered sign-in outlives the session, so a sign-in-time check is not enough.
        $middleware->web(append: [
            EnsureAccountCanSignIn::class,
            SetUserLocale::class,
            // A client's portal account never reaches a staff screen (spec §5.8).
            EnsureStaffUser::class,
        ]);
        $middleware->alias(['portal' => EnsurePortalUser::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // B10, the safety net: a refusal of PostgreSQL that no writer turned into a message goes back
        // to the form in plain words; the table, constraint and SQL go to the log, never to the screen.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! Refusal::isRefusal($e)) {
                return null;
            }
            $message = Refusal::message($e);

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->withInput()->with('error', $message);
        });
    })->create();
