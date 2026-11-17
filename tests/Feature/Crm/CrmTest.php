<?php

namespace Tests\Feature\Crm;

use App\Models\AuditLog;
use App\Models\CrmInteraction;
use App\Models\CrmTask;
use App\Models\Party;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §3.7, §5.1: the CRM log (append-only interactions) and follow-up tasks. */
class CrmTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function interaction(array $overrides = []): array
    {
        return array_merge([
            'channel' => 'whatsapp', 'happened_at' => now()->subHour()->format('Y-m-d\TH:i'),
            'summary' => 'Sent the catalogue; asked for prices on the blue cups.', 'next_step' => 'Send the PI',
        ], $overrides);
    }

    // ---- the tasks screen ----------------------------------------------------------

    public function test_the_tasks_screen_is_forbidden_without_crm_and_renders_with_it(): void
    {
        $this->actingAs($this->make(Roles::STAFF))->get('/crm/tasks')->assertForbidden();
        $this->actingAs($this->make(Roles::ACCOUNTANT))->get('/crm/tasks')->assertForbidden();
        $this->actingAs($this->make(Roles::STAFF))->post('/crm/tasks', ['title' => 'x', 'due_on' => today()->toDateString()])->assertForbidden();

        $sales = $this->make(Roles::SALES);
        CrmTask::factory()->create(['assigned_to' => $sales->id, 'title' => 'Call Ahmed back']);
        $this->actingAs($sales)->get('/crm/tasks')->assertOk()->assertSee('Call Ahmed back')->assertSee('action="'.route('crm.tasks.store').'"', false);
    }

    public function test_a_user_without_crm_edit_only_sees(): void
    {
        Role::create(['name' => 'crm_reader', 'guard_name' => 'web'])->givePermissionTo('crm.view');
        $reader = $this->make('crm_reader');
        $task = CrmTask::factory()->create(['assigned_to' => $reader->id, 'title' => 'Read only task']);

        $this->actingAs($reader)->get('/crm/tasks')->assertOk()->assertSee('Read only task')
            ->assertDontSee('action="'.route('crm.tasks.store').'"', false)->assertDontSee(route('crm.tasks.complete', $task));
        $this->actingAs($reader)->post('/crm/tasks', ['title' => 'x', 'due_on' => today()->toDateString(), 'assigned_to' => $reader->id])->assertForbidden();
        $this->actingAs($reader)->post("/crm/tasks/{$task->id}/complete")->assertForbidden();
        $this->assertNull($task->fresh()->done_at);
    }

    public function test_my_open_tasks_come_overdue_first_and_the_filters_work(): void
    {
        $me = $this->make(Roles::SALES);
        $colleague = $this->make(Roles::LOGISTICS);
        CrmTask::factory()->create(['assigned_to' => $me->id, 'title' => 'Later task', 'due_on' => today()->addDays(5)]);
        CrmTask::factory()->create(['assigned_to' => $me->id, 'title' => 'Overdue task', 'due_on' => today()->subDays(3)]);
        CrmTask::factory()->create(['assigned_to' => $me->id, 'title' => 'Finished task', 'due_on' => today(), 'done_at' => now(), 'done_by' => $me->id]);
        CrmTask::factory()->create(['assigned_to' => $colleague->id, 'title' => 'Colleague task']);

        $this->actingAs($me)->get('/crm/tasks')->assertOk()
            ->assertSeeInOrder(['Overdue task', 'Later task'])
            ->assertDontSee('Finished task')->assertDontSee('Colleague task')
            ->assertSee('app-pill--late', false);
        $this->actingAs($me)->get('/crm/tasks?filter=all')->assertSee('Colleague task')->assertSee('Overdue task')->assertDontSee('Finished task');
        $this->actingAs($me)->get('/crm/tasks?filter=done')->assertSee('Finished task')->assertDontSee('Later task');
    }

    public function test_a_task_is_created_completed_and_reopened_and_each_step_is_audited(): void
    {
        $sales = $this->make(Roles::SALES);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id]);

        $this->actingAs($sales)->from('/crm/tasks')->post('/crm/tasks', [
            'title' => 'Chase the deposit', 'due_on' => today()->addDays(2)->toDateString(),
            'assigned_to' => $sales->id, 'party_id' => $client->id, 'notes' => '30% of PI-2026-0001',
        ])->assertRedirect('/crm/tasks');
        $task = CrmTask::firstOrFail();
        $this->assertSame($client->id, $task->party_id);
        $this->assertSame($sales->id, $task->created_by);
        $this->assertFalse($task->isDone());

        $this->actingAs($sales)->post("/crm/tasks/{$task->id}/complete")->assertRedirect();
        $task->refresh();
        $this->assertTrue($task->isDone());
        $this->assertSame($sales->id, $task->done_by);

        $this->actingAs($sales)->post("/crm/tasks/{$task->id}/complete")->assertSessionHasErrors('task');

        $this->actingAs($sales)->post("/crm/tasks/{$task->id}/reopen")->assertRedirect();
        $task->refresh();
        $this->assertFalse($task->isDone());
        $this->assertNull($task->done_by);

        $this->assertSame(['created', 'completed', 'reopened'], AuditLog::where('model_type', CrmTask::class)->orderBy('id')->pluck('action')->all());
    }

    public function test_tasks_about_another_sales_users_client_stay_out_of_sight(): void
    {
        $mine = $this->make(Roles::SALES);
        $theirs = $this->make(Roles::SALES);
        $otherClient = Party::factory()->client()->create(['account_manager_id' => $theirs->id]);
        $task = CrmTask::factory()->create(['assigned_to' => $theirs->id, 'created_by' => $theirs->id, 'party_id' => $otherClient->id, 'title' => 'Their secret follow-up']);

        $this->actingAs($mine)->get('/crm/tasks?filter=all')->assertDontSee('Their secret follow-up');
        $this->actingAs($mine)->post("/crm/tasks/{$task->id}/complete")->assertNotFound();
        $this->actingAs($mine)->post('/crm/tasks', ['title' => 'x', 'due_on' => today()->toDateString(), 'assigned_to' => $mine->id, 'party_id' => $otherClient->id])->assertNotFound();
        $this->assertSame(1, CrmTask::count());
    }

    // ---- interactions --------------------------------------------------------------

    public function test_an_interaction_is_recorded_and_audited_and_shown_newest_first(): void
    {
        $sales = $this->make(Roles::SALES);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id]);

        $this->actingAs($sales)->from("/clients/{$client->id}")->post(route('crm.interactions.store', $client), $this->interaction(['summary' => 'Older talk', 'happened_at' => now()->subDays(2)->format('Y-m-d\TH:i')]))
            ->assertRedirect("/clients/{$client->id}#crm");
        $this->actingAs($sales)->post(route('crm.interactions.store', $client), $this->interaction(['summary' => 'Newer talk', 'channel' => 'call']));

        $this->assertSame(2, CrmInteraction::count());
        $i = CrmInteraction::where('summary', 'Newer talk')->first();
        $this->assertSame($sales->id, $i->user_id);
        $this->assertSame('call', $i->channel);
        $this->assertSame(2, AuditLog::where('model_type', CrmInteraction::class)->where('action', 'created')->count());

        $this->actingAs($sales)->get("/clients/{$client->id}")->assertOk()->assertSeeInOrder(['Newer talk', 'Older talk']);
    }

    public function test_an_interaction_is_append_only(): void
    {
        $sales = $this->make(Roles::SALES);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id]);
        $this->actingAs($sales)->post(route('crm.interactions.store', $client), $this->interaction());
        $id = CrmInteraction::firstOrFail()->id;

        foreach ([
            fn () => DB::table('crm_interactions')->where('id', $id)->update(['summary' => 'rewritten']),
            fn () => DB::table('crm_interactions')->where('id', $id)->delete(),
        ] as $write) {
            DB::beginTransaction();
            try {
                $write();
                $this->fail('the database accepted a change to an interaction');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }

        $this->assertFalse(app('router')->has('crm.interactions.update'));
        $this->assertFalse(app('router')->has('crm.interactions.destroy'));
    }

    public function test_a_next_step_with_a_due_date_becomes_my_task(): void
    {
        $sales = $this->make(Roles::SALES);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id]);

        $this->actingAs($sales)->post(route('crm.interactions.store', $client), $this->interaction(['next_step_due' => today()->addDays(3)->toDateString()]))
            ->assertSessionHasNoErrors();

        $task = CrmTask::firstOrFail();
        $this->assertSame('Send the PI', $task->title);
        $this->assertSame($sales->id, $task->assigned_to);
        $this->assertSame($client->id, $task->party_id);
        $this->assertSame(today()->addDays(3)->toDateString(), $task->due_on->toDateString());
    }

    public function test_recording_an_interaction_needs_crm_edit_and_a_party_one_may_open(): void
    {
        $accountant = $this->make(Roles::ACCOUNTANT); // clients.view, no crm
        $logistics = $this->make(Roles::LOGISTICS);   // crm.edit, no suppliers
        $client = Party::factory()->client()->create();
        $supplier = Party::factory()->supplier()->create();

        $this->actingAs($accountant)->post(route('crm.interactions.store', $client), $this->interaction())->assertForbidden();
        $this->actingAs($logistics)->post(route('crm.interactions.store', $supplier), $this->interaction())->assertNotFound();
        $this->actingAs($logistics)->post(route('crm.interactions.store', $client), $this->interaction(['channel' => 'carrier_pigeon']))
            ->assertSessionHasErrorsIn('interaction', ['channel']);
        $this->assertSame(0, CrmInteraction::count());

        $this->actingAs($logistics)->post(route('crm.interactions.store', $client), $this->interaction())->assertSessionHasNoErrors();
        $this->assertSame(1, CrmInteraction::count());
    }

    public function test_the_party_page_shows_open_tasks_and_completes_them(): void
    {
        $sales = $this->make(Roles::SALES);
        $client = Party::factory()->client()->create(['account_manager_id' => $sales->id]);
        $task = CrmTask::factory()->create(['assigned_to' => $sales->id, 'party_id' => $client->id, 'title' => 'Visit the showroom']);
        CrmTask::factory()->create(['assigned_to' => $sales->id, 'party_id' => $client->id, 'title' => 'Already done', 'done_at' => now(), 'done_by' => $sales->id]);

        $this->actingAs($sales)->get("/clients/{$client->id}")->assertOk()
            ->assertSee('Visit the showroom')->assertDontSee('Already done')
            ->assertSee(route('crm.tasks.complete', $task));

        $this->actingAs($sales)->from("/clients/{$client->id}")->post(route('crm.tasks.complete', $task))->assertRedirect("/clients/{$client->id}");
        $this->assertTrue($task->fresh()->isDone());
    }
}
