<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account that may no longer sign in may no longer stay signed in.
 *
 * A remembered sign-in lasts thirty days, so checking users.status at sign-in alone is
 * not enough: the gate sits on every request. It is the same User::canSignIn() the
 * sign-in paths ask, and it costs nothing — the user is already loaded.
 *
 * Logging out here cycles the remember token, so every other device holding a cookie
 * for the account is signed out on its next request too.
 */
class EnsureAccountCanSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && ! $user->canSignIn()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('This account is no longer active.')]);
        }

        return $next($request);
    }
}
