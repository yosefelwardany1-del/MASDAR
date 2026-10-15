<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\ResolvesSignInHome;
use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleSsoClient;
use App\Services\Auth\SsoOutcome;
use App\Services\Auth\SsoSignIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * "Continue with Google" — the browser round trip. The decisions live in SsoSignIn.
 */
class GoogleSsoController extends Controller
{
    use ResolvesSignInHome;

    private const STATE_KEY = 'sso_google_state';

    private const PENDING_KEY = 'sso_pending_email';

    public function __construct(private SsoSignIn $signIn) {}

    public function redirect(Request $request)
    {
        if (Auth::check()) {
            return redirect($this->homeFor(Auth::user()));
        }

        $client = GoogleSsoClient::make();
        abort_unless($client->enabled(), 404);

        if (! $client->configured()) {
            return redirect()->route('login')
                ->withErrors(['sso' => __('Sign in with Google is not set up yet. Ask an administrator.')]);
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($client->authUrl($state, route('sso.google.callback')));
    }

    public function callback(Request $request)
    {
        if (Auth::check()) {
            return redirect($this->homeFor(Auth::user()));
        }

        $client = GoogleSsoClient::make();
        abort_unless($client->enabled(), 404);

        // The state is consumed whether or not it matches: a callback is single-use.
        $state = $request->session()->pull(self::STATE_KEY);
        if (! $state || ! hash_equals($state, (string) $request->query('state', ''))) {
            return $this->failed(__('Google sign-in was rejected. Please try again.'));
        }

        if ($request->query('error') === 'access_denied' || ! $request->filled('code')) {
            return $this->failed(__('Google sign-in was cancelled.'));
        }

        $profile = $client->exchange((string) $request->query('code'), route('sso.google.callback'));
        if (! $profile) {
            return $this->failed(__('Could not complete Google sign-in. Please try again.'));
        }

        $outcome = $this->signIn->resolve($profile);

        if ($outcome->is(SsoOutcome::PENDING)) {
            $request->session()->put(self::PENDING_KEY, $outcome->user->email);

            return redirect()->route('sso.pending');
        }

        if ($outcome->is(SsoOutcome::REFUSED)) {
            return $this->failed($outcome->message);
        }

        $user = $outcome->user;

        // Remembered by default (sso.remember): staff have no password to type again,
        // and a session that lapsed after two quiet hours meant signing in several
        // times a day. The remember cookie is bounded by auth.guards.web.remember and
        // EnsureAccountCanSignIn re-checks the account on every request it is used.
        Auth::login($user, (bool) config('sso.remember', true));
        $request->session()->regenerate();
        $user->update(['last_login' => now()]);

        return redirect($this->destinationAfterSignIn($user))
            ->with('success', __('Welcome back, :name!', ['name' => $user->name]));
    }

    /** The waiting page for an account that exists but has not been assigned yet. */
    public function pending(Request $request)
    {
        if (Auth::check()) {
            return redirect($this->homeFor(Auth::user()));
        }

        $email = $request->session()->get(self::PENDING_KEY);
        if (! $email) {
            return redirect()->route('login');
        }

        return view('auth.pending', ['email' => $email]);
    }

    private function failed(string $message)
    {
        return redirect()->route('login')->withErrors(['sso' => $message]);
    }
}
