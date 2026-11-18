<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\User;
use App\Services\Portal\ClientPortalService;
use Illuminate\Http\Request;

/** Giving a client a portal account, and taking it away, from the client's page (spec §5.8). */
class PortalAccessController extends Controller
{
    public function store(Request $request, Party $client, ClientPortalService $portal)
    {
        $data = $request->validate(['email' => 'required|string|max:160', 'name' => 'nullable|string|max:160']);
        [$user, $password] = $portal->grant($client, $data['email'], (string) ($data['name'] ?? ''), $request->user());

        return back()->with('success', __('Portal account made for :email. Its first password is :password — give it to the client now; it is not shown again.', ['email' => $user->email, 'password' => $password]));
    }

    public function destroy(Request $request, Party $client, User $account, ClientPortalService $portal)
    {
        abort_unless($account->party_id === $client->id, 404);
        $portal->revoke($account, $request->user());

        return back()->with('success', __('Portal access of :email taken away.', ['email' => $account->email]));
    }
}
