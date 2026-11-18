<?php

namespace App\Http\Middleware;

use App\Services\Portal\ClientPortalService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The portal is for a client's own account, and only while it is switched on (spec §5.8). */
class EnsurePortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->isPortalUser(), 404);
        abort_unless(ClientPortalService::enabled(), 403, __('The client portal is closed for now.'));

        return $next($request);
    }
}
