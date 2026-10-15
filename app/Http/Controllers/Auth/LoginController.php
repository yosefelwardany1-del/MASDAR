<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\ResolvesSignInHome;
use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleSsoClient;
use App\Services\Auth\SsoSignIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    use ResolvesSignInHome;

    public function __construct(private SsoSignIn $sso) {}

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect($this->homeFor(Auth::user()));
        }

        $google = GoogleSsoClient::make();

        return view('auth.login', [
            // The button shows only when it would work: a button that leads to "not
            // configured" is worse than no button.
            'googleSignIn' => $google->enabled() && $google->configured(),
            // Retired passwords fold the form behind an "emergency sign-in" link, kept
            // for the break-glass accounts.
            'passwordsRetired' => $google->enabled() && (bool) config('sso.passwords_retired', false),
        ]);
    }

    public function login(Request $request)
    {
        if (Auth::check()) {
            return redirect($this->homeFor(Auth::user()));
        }

        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Retired passwords are refused BEFORE they are checked: once an address signs in
        // with Google, testing its password would only tell an attacker whether it was right.
        if ($this->sso->passwordRetiredFor($request->input('email'))) {
            return back()
                ->withErrors(['email' => __('Passwords are retired for these accounts. Sign in with Google instead.')])
                ->withInput($request->only('email'));
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        if (Auth::attempt($credentials, $remember)) {
            $user = Auth::user();

            // The password was right; the ACCOUNT still has to be allowed in.
            //
            // A status other than 'active' means "no session", not "a session with nothing
            // in it". Said plainly rather than hidden behind the generic failure: whoever is
            // reading this already supplied the correct password, so it discloses
            // nothing they could not already confirm, and the alternative is somebody
            // retyping a password that was never the problem.
            if (! $user->canSignIn()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()
                    ->withErrors(['email' => __('This account is not active. Contact your administrator.')])
                    ->withInput($request->only('email'));
            }

            $request->session()->regenerate();
            $user->update(['last_login' => now()]);

            return redirect($this->destinationAfterSignIn($user))
                ->with('success', __('Welcome back, :name!', ['name' => $user->name]));
        }

        return back()->withErrors(['email' => __('The provided credentials do not match our records.')])->withInput($request->only('email'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', __('You have been signed out.'));
    }
}
