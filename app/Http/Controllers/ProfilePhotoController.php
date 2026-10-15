<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Media\ImageStore;
use Illuminate\Http\Request;

/**
 * The photo on a user's own profile — the face the sidebar, the top bar and anywhere a
 * login is shown will use instead of initials.
 *
 * Writing is SELF ONLY, deliberately. A login's picture is a personal setting, not an
 * administrative field: there is no "edit-users" path in here, so no permission holder can
 * quietly change what someone else's face looks like to everybody else.
 *
 * Reading is any authenticated user. A colleague's face is no more privileged than their
 * name, which already appears beside every card, comment and mention they touch.
 */
class ProfilePhotoController extends Controller
{
    public function __construct(private ImageStore $images) {}

    public function store(Request $request)
    {
        $request->validate(['photo' => ImageStore::rules()]);

        $this->images->store($request->user(), $request->file('photo'), 'user-photos', 'avatar');

        return back()->with('success', __('Profile photo updated.'));
    }

    public function destroy(Request $request)
    {
        $this->images->delete($request->user(), 'avatar');

        return back()->with('success', __('Profile photo removed.'));
    }

    public function show(User $user)
    {
        return $this->images->stream($user, 'avatar');
    }
}
