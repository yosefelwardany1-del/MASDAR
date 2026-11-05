@extends('layouts.app')

@section('title', __('Notifications'))

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">{{ __('Notifications') }}</h1>
        @if($unreadCount > 0)<span class="text-muted small">{{ __(':count unread', ['count' => $unreadCount]) }}</span>@endif
    </div>
    <div class="d-flex gap-2">
        <div class="btn-group">
            <a href="{{ route('notifications.index') }}" class="btn btn-sm {{ ($filter ?? null) ? 'btn-light' : 'btn-primary' }}">{{ __('All') }}</a>
            <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" class="btn btn-sm {{ ($filter ?? null) === 'unread' ? 'btn-primary' : 'btn-light' }}">{{ __('Unread') }}</a>
        </div>
        @if($unreadCount > 0)
            <form action="{{ route('notifications.read-all') }}" method="POST">
                @csrf @method('PUT')
                <button class="btn btn-light btn-sm"><i class="bi bi-check2-all me-1"></i>{{ __('Mark all read') }}</button>
            </form>
        @endif
    </div>
</div>

<div class="app-card">
    @forelse($notifications as $n)
        <div class="d-flex align-items-start gap-3 p-3 border-bottom {{ $n->is_read ? '' : 'bg-light' }}">
            @php $tone = match($n->type ?? 'info') { 'success' => 'success', 'warning' => 'warning', 'danger' => 'danger', default => 'primary' }; @endphp
            <div class="rounded-circle bg-{{ $tone }}-subtle text-{{ $tone }} d-flex align-items-center justify-content-center flex-shrink-0" style="inline-size:40px;block-size:40px;">
                <i class="bi bi-{{ $n->icon ?? 'bell' }}"></i>
            </div>
            <div class="flex-grow-1">
                <div class="fw-semibold">
                    @if($n->url)<a href="{{ $n->url }}" class="text-decoration-none text-reset">{{ $n->title }}</a>@else{{ $n->title }}@endif
                </div>
                <div class="text-muted small">{{ $n->message }}</div>
                <div class="text-muted small">{{ $n->created_at?->diffForHumans() }}</div>
            </div>
            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <form action="{{ route($n->is_read ? 'notifications.unread' : 'notifications.read', $n->id) }}" method="POST">
                    @csrf @method('PUT')
                    <button class="btn btn-sm btn-light" title="{{ $n->is_read ? __('Mark as unread') : __('Mark as read') }}">
                        <i class="bi {{ $n->is_read ? 'bi-arrow-counterclockwise' : 'bi-check' }}"></i>
                    </button>
                </form>
                <form action="{{ route('notifications.destroy', $n->id) }}" method="POST" onsubmit="return confirm(@js(__('Remove this notification?')));">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-light text-danger" title="{{ __('Remove') }}"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        </div>
    @empty
        <div class="app-empty"><i class="bi bi-bell-slash"></i>{{ __('No notifications') }}</div>
    @endforelse
    @if($notifications->hasPages())
        <div class="p-3">{{ $notifications->links() }}</div>
    @endif
</div>
@endsection
