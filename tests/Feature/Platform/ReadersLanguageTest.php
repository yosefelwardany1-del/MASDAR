<?php

namespace Tests\Feature\Platform;

use App\Models\ErpNotification;
use App\Models\ImportRun;
use App\Models\User;
use App\Security\Roles;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\LeaveService;
use App\Services\Notifications\NotificationService;
use App\Support\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * B31: everything a person reads is in their own language — an import's status, a notification
 * (whoever caused it, and even after they switch language), the pages of a long list, and
 * Laravel's own messages.
 */
class ReadersLanguageTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_an_import_status_is_a_word_not_a_key(): void
    {
        $manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
        ImportRun::create(['user_id' => $manager->id, 'resource' => 'products', 'filename' => 'products.csv', 'is_preview' => false,
            'total_rows' => 3, 'created_count' => 3, 'updated_count' => 0, 'failed_count' => 0, 'status' => ImportRun::COMPLETED]);

        $this->actingAs($manager)->get(route('import-export.index'))->assertOk()->assertSee('Completed')->assertDontSee('import.completed');
        $manager->update(['locale' => 'zh_CN']);
        $this->actingAs($manager->fresh())->get(route('import-export.index'))->assertOk()->assertSee('已完成');
    }

    public function test_a_notification_is_shown_in_its_readers_language(): void
    {
        $this->travelTo('2026-11-18 09:00:00');
        $arabic = User::factory()->create(['locale' => 'ar'])->assignRole(Roles::MANAGER);
        $chinese = User::factory()->create(['locale' => 'zh_CN'])->assignRole(Roles::MANAGER);
        $hr = User::factory()->create(['locale' => 'en'])->assignRole(Roles::HR);
        $employee = app(EmployeeService::class)->create(['name' => 'Ahmed Saleh', 'joined_on' => '2024-01-08'], $hr);

        // Raised by an English-speaking HR user; each manager reads it in their own language.
        app()->setLocale('en');
        app(LeaveService::class)->request($employee, ['leave_type' => 'annual', 'starts_on' => '2026-11-19', 'ends_on' => '2026-11-19'], $hr);

        $this->actingAs($arabic)->get(route('notifications.index'))->assertOk()
            ->assertSee('إجازات بانتظار القرار')->assertSee('إجازة سنوية')->assertDontSee('Leave to decide');
        $this->actingAs($chinese)->get(route('notifications.index'))->assertOk()
            ->assertSee('年假')->assertDontSee('Leave to decide')->assertDontSee('إجازة');

        // Switching language changes what was already received; the row keeps an English copy.
        $arabic->update(['locale' => 'en']);
        $this->actingAs($arabic->fresh())->get(route('notifications.index'))->assertOk()
            ->assertSee('Leave to decide')->assertSee('Ahmed Saleh asks for 1.00 days of annual leave, 2026-11-19 to 2026-11-19.');
        $row = ErpNotification::where('user_id', $chinese->id)->sole();
        $this->assertSame('Leave to decide', $row->title);
        $this->assertSame('年假', Text::fromArray($row->texts['message']['params']['type']['text'])->render('zh_CN'));

        // A row from before B31 has only its words, and is shown as written.
        ErpNotification::create(['user_id' => $chinese->id, 'title' => 'Old notice', 'message' => 'Written before.', 'type' => 'info']);
        $this->actingAs($chinese)->get(route('notifications.index'))->assertOk()->assertSee('Old notice');
    }

    public function test_the_pages_of_a_long_list_are_in_the_readers_language(): void
    {
        $chinese = User::factory()->create(['locale' => 'zh_CN'])->assignRole(Roles::MANAGER);
        $service = app(NotificationService::class);
        foreach (range(1, 21) as $n) {
            $service->send($chinese, Text::of('Your account is ready'), Text::of(':name approved your account. You can sign in now.', ['name' => "User {$n}"]));
        }

        $page = $this->actingAs($chinese)->get(route('notifications.index'))->assertOk();
        $page->assertSee('下一页')->assertDontSee('Showing')->assertDontSee('results')->assertDontSee('pagination.next');
        $this->assertMatchesRegularExpression('/显示第 <span[^>]*>1<\/span> 至 <span[^>]*>20<\/span> 条，共 <span[^>]*>21<\/span> 条/u', $page->getContent());
    }

    public function test_laravels_own_messages_are_in_the_readers_language(): void
    {
        $arabic = User::factory()->create(['locale' => 'ar'])->assignRole(Roles::MANAGER);
        $this->actingAs($arabic)->from(route('clients.create'))->post(route('clients.store'), [])
            ->assertSessionHasErrors(['name' => 'هذا الحقل مطلوب.']);
    }
}
