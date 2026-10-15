<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
        $user->update($data);

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

        auth()->user()->update(['password' => Hash::make($request->password)]);

        return redirect()->route('profile')->with('success', __('Password updated.'));
    }
}
