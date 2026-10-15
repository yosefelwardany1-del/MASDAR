<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;

/**
 * The user's own notification centre. All queries flow through
 * NotificationService::scopeForUser, so a user can never read or change another
 * user's notifications.
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request)
    {
        $base = $this->notifications->scopeForUser((int) auth()->id());

        $filter = $request->query('filter');
        if ($filter === 'unread') {
            $base->where('is_read', false);
        }

        $notifications = $base->latest()->paginate(20)->withQueryString();
        $unreadCount = $this->notifications->unreadCountFor(auth()->user());

        return view('notifications.index', compact('notifications', 'unreadCount', 'filter'));
    }

    public function markRead($id)
    {
        $this->ownNotification($id)->update(['is_read' => true, 'read_at' => now()]);

        return redirect()->back()->with('success', __('Notification marked as read.'));
    }

    public function markUnread($id)
    {
        $this->ownNotification($id)->update(['is_read' => false, 'read_at' => null]);

        return redirect()->back()->with('success', __('Notification marked as unread.'));
    }

    public function markAllRead()
    {
        $this->notifications->scopeForUser((int) auth()->id())
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return redirect()->back()->with('success', __('All notifications marked as read.'));
    }

    public function destroy($id)
    {
        $this->ownNotification($id)->delete();

        return redirect()->back()->with('success', __('Notification removed.'));
    }

    /** Owner-scoped lookup; 404 if it is not the actor's own notification. */
    private function ownNotification($id)
    {
        $notification = $this->notifications->scopeForUser((int) auth()->id())->whereKey($id)->first();
        abort_unless($notification !== null, 404);

        // Return a fresh single-row query so callers can update()/delete() safely.
        return $notification->newQuery()->whereKey($notification->getKey());
    }
}
