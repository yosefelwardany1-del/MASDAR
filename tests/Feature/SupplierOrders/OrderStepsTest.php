<?php

namespace Tests\Feature\SupplierOrders;

use App\Models\Attachment;
use App\Models\BusinessSetting;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderStepEvent;
use App\Security\Roles;
use App\Services\Configuration\BusinessSettingsService;
use App\Services\SupplierOrders\OrderStepService;
use App\Services\SupplierOrders\StepState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.3: each step is marked done or not applicable, with date, user, note and files; the latest event wins. */
class OrderStepsTest extends TestCase
{
    use BuildsSupplierOrders, RefreshDatabase, SeedsReference;

    public function test_done_skip_and_reopen_are_events_and_the_latest_wins(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $order = $this->orderWithLines();
        $sample = $this->step('initial_sample');
        $today = Carbon::today()->toDateString();

        $this->actingAs($merch)->post(route('supplier-orders.steps.done', [$order, $sample]), ['happened_on' => Carbon::yesterday()->toDateString(), 'note' => 'Photos approved by client'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(StepState::DONE, app(OrderStepService::class)->statesFor($order)[$sample->id]->state);

        $this->actingAs($merch)->post(route('supplier-orders.steps.reopen', [$order, $sample]), ['reason' => 'Colour wrong on the bulk'])->assertSessionHasNoErrors();
        $this->actingAs($merch)->post(route('supplier-orders.steps.skip', [$order, $this->step('artwork')]), ['reason' => 'Stock goods'])->assertSessionHasNoErrors();

        $events = SupplierOrderStepEvent::where('supplier_order_id', $order->id)->orderBy('id')->get();
        $this->assertSame(['done', 'reopened', 'skipped'], $events->pluck('status')->all());
        $this->assertSame(Carbon::yesterday()->toDateString(), $events[0]->happened_on->toDateString());
        $this->assertSame('Photos approved by client', $events[0]->note);
        $this->assertSame('Colour wrong on the bulk', $events[1]->note);
        $this->assertSame($merch->id, $events[1]->user_id);

        $states = app(OrderStepService::class)->statesFor($order->fresh());
        $this->assertNotSame(StepState::DONE, $states[$sample->id]->state);
        $this->assertSame(StepState::SKIPPED, $states[$this->step('artwork')->id]->state);

        // Done again after the reopening; but not done twice in a row.
        $this->actingAs($merch)->post(route('supplier-orders.steps.done', [$order, $sample]), ['happened_on' => $today])->assertSessionHasNoErrors();
        $this->actingAs($merch)->post(route('supplier-orders.steps.done', [$order, $sample]), ['happened_on' => $today])->assertSessionHasErrors('step');
        $this->actingAs($merch)->post(route('supplier-orders.steps.done', [$order, $this->step('final_sample')]), ['happened_on' => Carbon::tomorrow()->toDateString()])->assertSessionHasErrors('happened_on');
        $this->actingAs($merch)->post(route('supplier-orders.steps.skip', [$order, $this->step('final_sample')]), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(StepState::DONE, app(OrderStepService::class)->statesFor($order)[$sample->id]->state);
        $this->assertSame(4, SupplierOrderStepEvent::count());
    }

    public function test_a_step_that_requires_a_document_refuses_without_a_file(): void
    {
        Storage::fake(config('attachments.disk'));
        $merch = $this->user(Roles::MERCHANDISER);
        $order = $this->orderWithLines();
        $contract = $this->step('contract_stamped');
        $this->assertTrue($contract->requires_attachment);

        $this->actingAs($merch)->post(route('supplier-orders.steps.done', [$order, $contract]), ['happened_on' => Carbon::today()->toDateString()])
            ->assertSessionHasErrors('files');
        $this->assertSame(0, SupplierOrderStepEvent::count());

        $this->actingAs($merch)->post(route('supplier-orders.steps.done', [$order, $contract]), [
            'happened_on' => Carbon::today()->toDateString(),
            'files' => [UploadedFile::fake()->create('stamped-contract.pdf', 40, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, SupplierOrderStepEvent::where('status', 'done')->count());
        $attachment = Attachment::sole();
        $this->assertSame(SupplierOrder::class, $attachment->attachable_type);
        $this->assertSame($order->id, $attachment->attachable_id);
        $this->assertStringContainsString('stamped-contract.pdf', $attachment->title);
        Storage::disk(config('attachments.disk'))->assertExists($attachment->path);
    }

    public function test_due_and_late_states_come_from_the_delivery_date_and_offsets(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $service = app(OrderStepService::class);
        $order = $this->orderWithLines(1, 10, ['delivery_date' => Carbon::today()->addDays(5)]);
        $service->markDone($order, $this->step('artwork'), $merch, Carbon::today()->toDateString());
        $service->skip($order, $this->step('final_sample'), $merch, 'Same as the initial sample');

        $states = $service->statesFor($order);
        $state = fn (string $code) => $states[$this->step($code)->id];

        // initial_sample: 21 days before delivery = 16 days ago → late.
        $this->assertSame(StepState::LATE, $state('initial_sample')->state);
        $this->assertSame(Carbon::today()->subDays(16)->toDateString(), $state('initial_sample')->dueOn->toDateString());
        // artwork done, final sample not applicable: neither is late although past due.
        $this->assertSame(StepState::DONE, $state('artwork')->state);
        $this->assertSame(StepState::SKIPPED, $state('final_sample')->state);
        // labelling: 3 days before delivery = in 2 days, inside the 2 warning days → due soon.
        $this->assertSame(StepState::DUE, $state('labeling_packaging')->state);
        // receiving: on the delivery date, 5 days out → open; contract: no offset → open with no due date.
        $this->assertSame(StepState::OPEN, $state('receive_goods')->state);
        $this->assertSame(StepState::OPEN, $state('contract_stamped')->state);
        $this->assertNull($state('contract_stamped')->dueOn);
        // loading: 3 days AFTER delivery.
        $this->assertSame(Carbon::today()->addDays(8)->toDateString(), $service->dueDateFor($order, $this->step('loading'))->toDateString());

        // A reopened step is open again, so late again.
        $service->reopen($order, $this->step('final_sample'), $merch, 'Client wants a final sample after all');
        $this->assertSame(StepState::LATE, $service->statesFor($order)[$this->step('final_sample')->id]->state);

        // Wider warning window: receiving (in 5 days) becomes due soon.
        app(BusinessSettingsService::class)->update(['step_warning_days' => 7]);
        $this->assertSame(7, BusinessSetting::current()->step_warning_days);
        $this->assertSame(StepState::DUE, $service->statesFor($order)[$this->step('receive_goods')->id]->state);

        // Only open orders are late; no delivery date, no due date.
        $order->update(['status' => 'completed']);
        $this->assertSame(StepState::OPEN, $service->statesFor($order->fresh())[$this->step('initial_sample')->id]->state);
        $undated = $this->orderWithLines(1, 10, ['delivery_date' => null]);
        $this->assertNull($service->statesFor($undated)[$this->step('initial_sample')->id]->dueOn);
    }

    public function test_the_board_needs_one_query_for_all_events(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $service = app(OrderStepService::class);
        $orders = collect([$this->orderWithLines(), $this->orderWithLines(), $this->orderWithLines()]);
        foreach ($orders as $order) {
            $service->markDone($order, $this->step('artwork'), $merch, Carbon::today()->toDateString());
        }
        $steps = $service->activeSteps();
        BusinessSetting::current();

        DB::enableQueryLog();
        $board = $service->boardStates($orders, $steps);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'supplier_order_step_events'));
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertCount(3, $board);
        $this->assertSame(StepState::DONE, $board[$orders[2]->id][$this->step('artwork')->id]->state);
    }

    public function test_a_payment_step_links_only_a_payment_of_this_order(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $order = $this->orderWithLines();
        $other = $this->orderWithLines();
        $account = MoneyAccount::factory()->rmb()->create();
        $pay = fn (SupplierOrder $o) => Payment::create([
            'number' => 'PAY-T-'.$o->id, 'paid_on' => Carbon::today()->toDateString(), 'kind' => 'supplier_payment', 'direction' => 'out',
            'money_account_id' => $account->id, 'currency' => $account->currency, 'amount' => 1000, 'rate' => '0.140000',
            'party_id' => $o->supplier_id, 'supplier_order_id' => $o->id, 'user_id' => $merch->id,
        ]);
        $mine = $pay($order);
        $theirs = $pay($other);
        $service = app(OrderStepService::class);

        try {
            $service->markDone($order, $this->step('deposit'), $merch, Carbon::today()->toDateString(), null, $theirs->id);
            $this->fail('a payment of another order was linked');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_id', $e->errors());
        }
        try {
            $service->markDone($order, $this->step('artwork'), $merch, Carbon::today()->toDateString(), null, $mine->id);
            $this->fail('a manual step was linked to a payment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_id', $e->errors());
        }

        $event = $service->markDone($order, $this->step('deposit'), $merch, Carbon::today()->toDateString(), '30% deposit', $mine->id);
        $this->assertSame($mine->id, $event->payment_id);
        $this->actingAs($merch)->get(route('supplier-orders.show', $order))->assertOk()->assertSee($mine->number);
    }

    public function test_a_cancelled_order_takes_no_step(): void
    {
        $merch = $this->user(Roles::MERCHANDISER);
        $order = $this->orderWithLines(1, 10, ['status' => 'cancelled']);

        $this->actingAs($merch)->post(route('supplier-orders.steps.done', [$order, $this->step('artwork')]), ['happened_on' => Carbon::today()->toDateString()])
            ->assertSessionHasErrors('step');
        $this->assertSame(0, SupplierOrderStepEvent::count());
    }
}
