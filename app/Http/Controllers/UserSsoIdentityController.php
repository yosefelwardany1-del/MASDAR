<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSsoIdentity;
use App\Security\RoleManagementGuard;
use App\Services\Auth\SsoSignIn;
use Illuminate\Http\Request;

/**
 * An admin links a second Google address to somebody, or removes one.
 *
 * Gated like the rest of user management: users.edit on the route, and the same
 * canManageUser rank check UserController applies, so an admin cannot attach an address
 * to an account they could not otherwise edit — which would be a way of taking it over.
 */
class UserSsoIdentityController extends Controller
{
    public function __construct(private RoleManagementGuard $guard, private SsoSignIn $sso) {}

    public function store(Request $request, User $user)
    {
        abort_unless($this->guard->canManageUser($request->user(), $user), 403);

        $data = $request->validate(['email' => ['required', 'string', 'max:190']]);

        try {
            $identity = $this->sso->linkAddress($user, $data['email'], $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['sso_email' => $e->getMessage()])->withInput();
        }

        return back()->with('success', __(':email can now sign in as :name.', ['email' => $identity->email, 'name' => $user->name]));
    }

    public function destroy(Request $request, User $user, UserSsoIdentity $identity)
    {
        abort_unless($this->guard->canManageUser($request->user(), $user), 403);
        abort_unless($identity->user_id === $user->id, 404);

        // The primary address is not removable here: it is users.email, and removing its
        // link would only make the next sign-in re-create it.
        if ($identity->email === UserSsoIdentity::normalise($user->email)) {
            return back()->withErrors(['sso_email' => __("That is the account's own e-mail address; change it on the form above instead.")]);
        }

        $identity->delete();

        return back()->with('success', __('Address unlinked.'));
    }
}
