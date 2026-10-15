<?php

namespace Tests\Feature\Platform;

use App\Models\ErpNotification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationService is the one authority for raising notifications; the centre and the
 * bell only ever show the signed-in user's own rows.
 */
class NotificationCentreTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create();
        $this->service = app(NotificationService::class);
        $this->actingAs($this->me);
    }

    private function note(User $user, string $title, bool $read = false): ErpNotification
    {
        return ErpNotification::create(['user_id' => $user->id, 'title' => $title, 'message' => 'm', 'is_read' => $read]);
    }

    public function test_service_creates_an_in_app_notification_with_its_category(): void
    {
        $count = $this->service->send($this->me, 'Hello', 'World', [
            'type' => 'success', 'category' => NotificationType::SYSTEM, 'icon' => 'check',
        ]);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('erp_notifications', [
            'user_id' => $this->me->id, 'title' => 'Hello', 'category' => NotificationType::SYSTEM, 'is_read' => false,
        ]);
    }

    public function test_send_to_many_recipients_dedupes(): void
    {
        $other = User::factory()->create();

        $sent = $this->service->send([$this->me, $this->me->id, (string) $other->id, null], 'Bulk', 'msg');

        $this->assertSame(2, $sent);
        $this->assertSame(2, ErpNotification::where('title', 'Bulk')->count());
    }

    public function test_notification_centre_is_owner_scoped(): void
    {
        $other = User::factory()->create();
        $mine = $this->note($this->me, 'Mine');
        $theirs = $this->note($other, 'Theirs');

        $this->get(route('notifications.index'))->assertOk()->assertSee('Mine')->assertDontSee('Theirs');

        $this->put(route('notifications.read', $theirs->id))->assertNotFound();
        $this->delete(route('notifications.destroy', $theirs->id))->assertNotFound();
        $this->assertFalse($theirs->fresh()->is_read);

        $this->put(route('notifications.read', $mine->id))->assertRedirect();
        $this->assertTrue($mine->fresh()->is_read);
        $this->put(route('notifications.unread', $mine->id))->assertRedirect();
        $this->assertFalse($mine->fresh()->is_read);
        $this->delete(route('notifications.destroy', $mine->id))->assertRedirect();
        $this->assertFalse(ErpNotification::whereKey($mine->id)->exists());
    }

    public function test_mark_all_read_touches_only_my_notifications(): void
    {
        $other = User::factory()->create();
        $this->note($this->me, 'A');
        $this->note($other, 'B');

        $this->put(route('notifications.read-all'))->assertRedirect();

        $this->assertSame(0, ErpNotification::where('user_id', $this->me->id)->where('is_read', false)->count());
        $this->assertSame(1, ErpNotification::where('user_id', $other->id)->where('is_read', false)->count());
    }

    public function test_bell_shows_unread_count(): void
    {
        $this->note($this->me, 'One');
        $this->note($this->me, 'Two');
        $this->note($this->me, 'Old', read: true);
        $this->note(User::factory()->create(), 'Not mine');

        $this->get('/')->assertOk()
            ->assertSee('data-unread="2"', false)
            ->assertSee('One')
            ->assertDontSee('Not mine');
    }

    public function test_a_non_numeric_id_is_not_found_not_a_server_error(): void
    {
        // PostgreSQL refuses 'abc' for a bigint column; the route must never get that far.
        $this->put('/notifications/abc/read')->assertNotFound();
        $this->delete('/notifications/abc')->assertNotFound();
    }

    public function test_centre_page_is_in_arabic(): void
    {
        $this->get(route('notifications.index'))->assertOk()->assertSee('الإشعارات');
    }
}
