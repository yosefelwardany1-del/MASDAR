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
            // HR (spec §5.7): documents for those who keep the employee file, leave for those who decide it.
            'documents' => $user->can('employees.edit') ? $attention->expiringDocuments(10) : collect(),
            'pendingLeave' => $user->can('leave.approve') ? $attention->pendingLeave(10)->reject(fn ($r) => $r->employee?->user_id === $user->id)->values() : collect(),
            'seesOrders' => $seesOrders,
            'seesContainers' => $seesContainers,
        ]);
    }
}
