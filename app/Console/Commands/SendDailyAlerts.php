<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Security\Roles;
use App\Services\Dashboard\AttentionQueries;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Each morning (China time), one in-app summary per person (spec §7): the order steps late or
 * due on the orders they follow, the containers late or arriving for the clients they manage,
 * and their own tasks due. One message per person per day, never one per row.
 */
class SendDailyAlerts extends Command
{
    protected $signature = 'masdar:daily-alerts';

    protected $description = 'Send each user a summary of late order steps, arriving containers and tasks due';

    public function handle(AttentionQueries $attention, NotificationService $notifications): int
    {
        $sent = 0;
        $managers = User::role(Roles::MANAGER)->where('status', 'active')->get();

        foreach (User::where('status', 'active')->get() as $user) {
            $lines = [];

            if ($user->can('supplier_orders.view')) {
                $mine = $user->hasRole(Roles::MERCHANDISER) && ! $managers->contains($user) ? $user->id : null;
                $steps = $mine || $managers->contains($user) ? $attention->steps($mine, 1000) : collect();
                if ($late = $steps->where('late', true)->count()) {
                    $lines[] = trans_choice('{1} One order step is late.|[2,*] :count order steps are late.', $late, ['count' => $late]);
                }
                if ($due = $steps->where('late', false)->count()) {
                    $lines[] = trans_choice('{1} One order step is due soon.|[2,*] :count order steps are due soon.', $due, ['count' => $due]);
                }
            }

            if ($user->can('containers.view') && ($user->hasRole([Roles::SALES, Roles::LOGISTICS]) || $managers->contains($user))) {
                $own = $user->hasRole(Roles::SALES) && ! $user->hasRole([Roles::LOGISTICS, Roles::MANAGER]) ? $user->id : null;
                $containers = $attention->containers($own, 1000);
                if ($late = $containers->where('late', true)->count()) {
                    $lines[] = trans_choice('{1} One container is past its ETA.|[2,*] :count containers are past their ETA.', $late, ['count' => $late]);
                }
                if ($soon = $containers->where('late', false)->count()) {
                    $lines[] = trans_choice('{1} One container arrives soon.|[2,*] :count containers arrive soon.', $soon, ['count' => $soon]);
                }
            }

            // Unpaid before shipping (spec §7): account managers for their clients; the
            // accountant and managers for everyone.
            $everyone = $managers->contains($user) || $user->hasRole(Roles::ACCOUNTANT);
            if ($user->can('proforma_invoices.view') && ($everyone || $user->hasRole(Roles::SALES))) {
                $unpaid = $attention->unpaidBooked($everyone ? null : $user->id, 1000)->count();
                if ($unpaid) {
                    $lines[] = trans_choice('{1} One PI is booked to ship but not paid in full.|[2,*] :count PIs are booked to ship but not paid in full.', $unpaid, ['count' => $unpaid]);
                }
            }

            if ($user->can('crm.view') && ($tasks = $attention->tasks($user->id, 1000)->count())) {
                $lines[] = trans_choice('{1} One task is due.|[2,*] :count tasks are due.', $tasks, ['count' => $tasks]);
            }

            if ($lines !== []) {
                $notifications->send($user, __('Today at MASDAR'), implode(' ', $lines), [
                    'category' => 'masdar.daily', 'icon' => 'calendar-check', 'url' => Route::has('dashboard') ? route('dashboard') : null,
                ]);
                $sent++;
            }
        }

        $this->info("Daily alerts sent to {$sent} users.");

        return self::SUCCESS;
    }
}
