<?php

namespace App\Services\Crm;

use App\Models\CrmInteraction;
use App\Models\CrmTask;
use App\Models\Party;
use App\Models\User;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of the CRM log (spec §3.7): interactions with any party — append-only, held
 * by a trigger — and follow-up tasks, which are created, completed and reopened.
 */
class CrmService
{
    /**
     * Record one contact with a party. A next step with a due date also becomes a follow-up
     * task for the person who recorded it (spec §3.7: "the next step and its due date").
     *
     * @param  array{channel: string, happened_at: mixed, summary: string, next_step?: ?string, next_step_due?: ?string}  $data
     */
    public function recordInteraction(Party $party, array $data, User $by): CrmInteraction
    {
        return DB::transaction(function () use ($party, $data, $by) {
            $interaction = CrmInteraction::create([
                'party_id' => $party->id,
                'user_id' => $by->id,
                'channel' => $data['channel'],
                'happened_at' => $data['happened_at'],
                'summary' => $data['summary'],
                'next_step' => $data['next_step'] ?? null,
            ]);
            AuditTrail::record('created', $interaction, [], $interaction->only(['party_id', 'user_id', 'channel', 'happened_at', 'next_step']));

            if (filled($data['next_step'] ?? null) && filled($data['next_step_due'] ?? null)) {
                $this->createTask([
                    'party_id' => $party->id,
                    'assigned_to' => $by->id,
                    'title' => $data['next_step'],
                    'due_on' => $data['next_step_due'],
                ], $by);
            }

            return $interaction;
        });
    }

    /** @param array{title: string, due_on: mixed, assigned_to: int, party_id?: ?int, notes?: ?string} $data */
    public function createTask(array $data, User $by): CrmTask
    {
        return DB::transaction(function () use ($data, $by) {
            $task = CrmTask::create([
                'party_id' => $data['party_id'] ?? null,
                'assigned_to' => $data['assigned_to'],
                'title' => $data['title'],
                'notes' => $data['notes'] ?? null,
                'due_on' => $data['due_on'],
                'created_by' => $by->id,
            ]);
            AuditTrail::record('created', $task, [], $task->only(['party_id', 'assigned_to', 'title', 'due_on']));

            return $task;
        });
    }

    public function completeTask(CrmTask $task, User $by): CrmTask
    {
        if ($task->isDone()) {
            throw ValidationException::withMessages(['task' => __('This task is already done.')]);
        }

        return DB::transaction(function () use ($task, $by) {
            $task->update(['done_at' => now(), 'done_by' => $by->id]);
            AuditTrail::record('completed', $task, ['done_at' => null], ['done_at' => $task->done_at, 'done_by' => $by->id]);

            return $task;
        });
    }

    public function reopenTask(CrmTask $task, User $by): CrmTask
    {
        if (! $task->isDone()) {
            throw ValidationException::withMessages(['task' => __('This task is still open.')]);
        }

        return DB::transaction(function () use ($task) {
            $old = ['done_at' => $task->done_at, 'done_by' => $task->done_by];
            $task->update(['done_at' => null, 'done_by' => null]);
            AuditTrail::record('reopened', $task, $old, ['done_at' => null]);

            return $task;
        });
    }
}
