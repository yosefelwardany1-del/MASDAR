@inject('notificationService', 'App\Services\Notifications\NotificationService')
@php
    $unread = $notificationService->unreadCountFor(auth()->user());
    $recent = $notificationService->recentFor(auth()->user(), 8);
@endphp
<div class="dropdown" data-unread="{{ $unread }}">
    <button class="app-iconbtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="{{ __('Notifications') }}">
        <i class="bi bi-bell"></i>
        @if($unread > 0)<span class="app-badge">{{ $unread > 9 ? '9+' : $unread }}</span>@endif
    </button>
    <div class="dropdown-menu dropdown-menu-end shadow p-0" style="inline-size:340px;max-inline-size:92vw;">
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <span class="fw-semibold">{{ __('Notifications') }}</span>
            @if($unread > 0)
                <form action="{{ route('notifications.read-all') }}" method="POST" class="m-0">@csrf @method('PUT')
                    <button class="btn btn-link btn-sm p-0 text-decoration-none">{{ __('Mark all read') }}</button>
                </form>
            @endif
        </div>
        <div style="max-block-size:360px;overflow-y:auto;">
            @forelse($recent as $n)
                <a href="{{ $n->url ?: route('notifications.index') }}" class="d-flex align-items-start gap-2 px-3 py-2 border-bottom text-decoration-none text-reset {{ $n->is_read ? '' : 'bg-light' }}">
                    <i class="bi bi-{{ $n->icon ?? 'bell' }} text-primary mt-1"></i>
                    <div class="flex-grow-1">
                        <div class="small fw-semibold">{{ $n->displayTitle() }}</div>
                        <div class="text-muted small">{{ \Illuminate\Support\Str::limit($n->displayMessage(), 70) }}</div>
                        <div class="text-muted" style="font-size:11px;">{{ $n->created_at?->diffForHumans() }}</div>
                    </div>
                </a>
            @empty
                <div class="text-center text-muted small py-4"><i class="bi bi-bell-slash d-block mb-1"></i>{{ __('No notifications') }}</div>
            @endforelse
        </div>
        <a href="{{ route('notifications.index') }}" class="d-block text-center small py-2 text-decoration-none border-top">{{ __('View all notifications') }}</a>
    </div>
</div>
