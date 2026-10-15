<?php

use App\Http\Middleware\ProfileRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
