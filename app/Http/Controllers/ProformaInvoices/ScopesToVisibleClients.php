<?php

namespace App\Http\Controllers\ProformaInvoices;

use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spec §6: a sales user limited to their own clients (`Visibility::ownClientsOnly`) sees only
 * the PIs of clients they manage (`parties.account_manager_id`). Anyone else's PI is a 404,
 * the same as one that does not exist.
 */
trait ScopesToVisibleClients
{
    protected function abortUnlessVisible(User $user, ProformaInvoice $pi): void
    {
        abort_if(Visibility::ownClientsOnly($user) && (int) $pi->client?->account_manager_id !== (int) $user->id, 404);
    }

    /** Clients $user may raise or see a PI for, by name. */
    protected function visibleClients(User $user, bool $activeOnly = false): Builder
    {
        return Visibility::scopeClients(Party::query()->clients(), $user)
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderBy('name');
    }
}
