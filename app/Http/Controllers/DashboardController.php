<?php

namespace App\Http\Controllers;

use App\Security\Roles;
use App\Services\Dashboard\AttentionQueries;
use App\Support\Access\Visibility;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * The home page: what needs attention today, limited to what the user may see. Needs
     * sign-in only, so a user with no roles still lands here.
     */
    public function __invoke(Request $request, AttentionQueries $attention): View
    {
        $user = $request->user();
        $seesOrders = $user->can('supplier_orders.view');
        $seesContainers = $user->can('containers.view');
        $onlyMine = $user->hasRole(Roles::MERCHANDISER) && ! $user->hasRole(Roles::MANAGER);

        return view('dashboard', [
            'counts' => ($seesOrders || $seesContainers || $user->can('proforma_invoices.view')) ? $attention->counts() : null,
            'steps' => $seesOrders ? $attention->steps($onlyMine ? $user->id : null, 15) : collect(),
            'containers' => $seesContainers ? $attention->containers(Visibility::ownClientsOnly($user) ? $user->id : null, 15) : collect(),
            'tasks' => $user->can('crm.view') ? $attention->tasks($user->id) : collect(),
            'seesOrders' => $seesOrders,
            'seesContainers' => $seesContainers,
        ]);
    }
}
