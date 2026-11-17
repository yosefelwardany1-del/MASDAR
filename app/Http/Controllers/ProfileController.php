<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetUserLocale;
use App\Support\Audit\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        return view('profile.index', compact('user'));
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:20',
        ]);
        DB::transaction(function () use ($user, $data) {
            $old = $user->only(['name', 'email', 'phone']);
            $user->update($data);
            AuditTrail::record('updated', $user, $old, $user->only(['name', 'email', 'phone']));
        });

        return redirect()->route('profile')->with('success', __('Profile updated.'));
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($request->current_password, auth()->user()->password)) {
            return back()->with('error', __('Current password is incorrect.'));
        }

        DB::transaction(function () use ($request) {
            auth()->user()->update(['password' => Hash::make($request->password)]);
            auth()->user()->signOutEverywhere(keepSessionId: $request->session()->getId());
            AuditTrail::record('password_changed', auth()->user());
        });

        return redirect()->route('profile')->with('success', __('Password updated.'));
    }

    /** The signed-in user's screen language. */
    public function updateLocale(Request $request)
    {
        $data = $request->validate(['locale' => ['required', 'in:'.implode(',', array_keys(SetUserLocale::LOCALES))]]);
        $user = $request->user();

        DB::transaction(function () use ($user, $data) {
            $old = $user->only(['locale']);
            $user->update(['locale' => $data['locale']]);
            AuditTrail::record('updated', $user, $old, $user->only(['locale']));
        });

        return back();
    }
}
