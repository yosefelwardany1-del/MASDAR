<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a client's portal account (spec §5.8) out of the staff screens: whatever it asks for,
 * it is sent to the portal — but for signing out, its language and the logo on printed pages.
 */
class EnsureStaffUser
{
    private const ALLOWED = ['logout', 'profile.locale', 'settings.logo'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->isPortalUser() && ! in_array($request->route()?->getName(), self::ALLOWED, true) && ! $request->routeIs('portal.*')) {
            return redirect()->route('portal.home');
        }

        return $next($request);
    }
}
