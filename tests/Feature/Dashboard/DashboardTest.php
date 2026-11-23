<?php

namespace Tests\Feature\Dashboard;

use App\Models\Container;
use App\Models\CrmTask;
use App\Models\ErpNotification;
use App\Models\OrderStepDefinition;
use App\Models\Shipment;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Security\Roles;
use App\Services\Dashboard\AttentionQueries;
use App\Services\SupplierOrders\OrderStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LoadsContainers;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LoadsContainers, RefreshDatabase, SeedsReference;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    public function test_late_and_due_steps_come_from_the_delivery_date_and_offsets(): void
    {
        $merch = $this->make(Roles::MERCHANDISER);
        // Delivery in 5 days: initial sample (21 before) and artwork (21) and final sample (10) are late;
        // labelling (3 before) is due in 2 days, inside the default 2-day warning.
        $order = SupplierOrder::factory()->create(['delivery_date' => Carbon::today()->addDays(5), 'merchandiser_id' => $merch->id]);
        app(OrderStepService::class)->record($order, OrderStepDefinition::where('code', 'artwork')->first(), 'done', $merch, Carbon::today()->toDateString());

        $response = $this->actingAs($merch)->get('/')->assertOk();
        $response->assertSee($order->number)->assertSee(__('Order steps due or late'));

        $steps = app(AttentionQueries::class)->steps($merch->id);
        $this->assertEqualsCanonicalizing(['initial_sample', 'final_sample', 'labeling_packaging'], OrderStepDefinition::whereIn('id', $steps->pluck('step_id'))->pluck('code')->all());
        $this->assertSame(2, $steps->where('late', true)->count());
    }

    public function test_containers_past_eta_and_arriving_soon_are_listed_until_they_arrive(): void
    {
        $logistics = $this->make(Roles::LOGISTICS);
        $late = Container::factory()->create(['shipment_id' => Shipment::factory()->create(['eta' => Carbon::today()->subDays(2)])->id]);
        $soon = Container::factory()->create(['shipment_id' => Shipment::factory()->create(['eta' => Carbon::today()->addDays(2)])->id]);
        $arrived = Container::factory()->create(['shipment_id' => Shipment::factory()->create(['eta' => Carbon::today()->subDay()])->id]);
        $this->putGoodsIn($arrived);
        DB::table('container_events')->insert(['container_id' => $arrived->id, 'status' => 'arrived', 'happened_at' => now(), 'user_id' => $logistics->id]);

        $this->actingAs($logistics)->get('/')->assertOk()
            ->assertSee($late->container_no)->assertSee($soon->container_no)->assertDontSee($arrived->container_no);
    }

    public function test_a_user_without_roles_sees_only_a_welcome(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertDontSee(__('Order steps due or late'));
    }

    public function test_the_daily_alert_sends_one_summary_per_person(): void
    {
        $merch = $this->make(Roles::MERCHANDISER);
        SupplierOrder::factory()->count(2)->create(['delivery_date' => Carbon::today()->addDays(1), 'merchandiser_id' => $merch->id]);
        $sales = $this->make(Roles::SALES);
        CrmTask::factory()->create(['assigned_to' => $sales->id, 'due_on' => Carbon::today()]);
        $idle = $this->make(Roles::ACCOUNTANT);

        $this->artisan('masdar:daily-alerts')->assertSuccessful();

        $this->assertSame(1, ErpNotification::where('user_id', $merch->id)->count());
        $this->assertSame(1, ErpNotification::where('user_id', $sales->id)->count());
        $this->assertSame(0, ErpNotification::where('user_id', $idle->id)->count());
    }
}
