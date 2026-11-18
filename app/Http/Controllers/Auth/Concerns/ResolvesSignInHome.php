<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;

/**
 * Where a freshly signed-in person goes. Shared by the password and the Google paths so
 * the two can never disagree. The home page needs sign-in only, so it is a safe landing
 * for everybody; a page that bounced a guest to the login form is replayed afterwards and
 * its own permission middleware judges it.
 */
trait ResolvesSignInHome
{
    /** Where an already-authenticated user belongs. */
    private function homeFor(?User $user): string
    {
        return $user?->isPortalUser() ? route('portal.home') : route('dashboard');
    }

    /** The page that bounced them to login (never the login page itself), else home. */
    private function destinationAfterSignIn(User $user): string
    {
        if ($user->isPortalUser()) {
            session()->forget('url.intended');

            return route('portal.home');
        }
        $intended = session()->pull('url.intended', route('dashboard'));

        return in_array($intended, [route('login'), url('/login')], true) ? route('dashboard') : $intended;
    }
}
