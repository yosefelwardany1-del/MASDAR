@extends('layouts.app')

@section('title', __('Notifications'))

@section('content')
<x-page-header :title="__('Notifications')" :subtitle="$unreadCount > 0 ? __(':count unread', ['count' => $unreadCount]) : null">
    <x-slot:actions>
        <nav class="app-chips mb-0" aria-label="{{ __('Notifications') }}">
            <a href="{{ route('notifications.index') }}" class="app-chip {{ ($filter ?? null) ? '' : 'is-active' }}" @unless($filter ?? null) aria-current="page" @endunless>{{ __('All') }}</a>
            <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" class="app-chip {{ ($filter ?? null) === 'unread' ? 'is-active' : '' }}" @if(($filter ?? null) === 'unread') aria-current="page" @endif>{{ __('Unread') }}@if($unreadCount > 0) <span class="app-chip__count app-num">{{ $unreadCount }}</span>@endif</a>
        </nav>
        @if($unreadCount > 0)
            <form action="{{ route('notifications.read-all') }}" method="POST">
                @csrf @method('PUT')
                <button class="btn btn-light btn-sm"><i class="bi bi-check2-all" aria-hidden="true"></i>{{ __('Mark all read') }}</button>
            </form>
        @endif
    </x-slot:actions>
</x-page-header>

<div class="app-card">
    @if($notifications->isEmpty())
        <x-empty icon="bell-slash" :title="__('No notifications')" />
    @else
        <ul class="app-notes">
            @foreach($notifications as $n)
                @php $tone = match($n->type ?? 'info') { 'success' => 'success', 'warning' => 'warning', 'danger' => 'danger', default => 'info' }; @endphp
                <li class="app-notes__row {{ $n->is_read ? '' : 'is-unread' }}">
                    <span class="app-notes__icon app-notes__icon--{{ $tone }}"><i class="bi bi-{{ $n->icon ?? 'bell' }}" aria-hidden="true"></i></span>
                    <div class="app-notes__main">
                        @if($n->url)<a href="{{ $n->url }}" class="app-notes__title">{{ $n->title }}</a>@else<span class="app-notes__title">{{ $n->title }}</span>@endif
                        @unless($n->is_read)<span class="app-notes__new"><i class="bi bi-circle-fill" style="font-size:.45rem" aria-hidden="true"></i>{{ __('Unread') }}</span>@endunless
                        <div class="app-notes__text">{{ $n->message }}</div>
                        <div class="app-notes__time">{{ $n->created_at?->diffForHumans() }}</div>
                    </div>
                    <div class="app-notes__actions">
                        <form action="{{ route($n->is_read ? 'notifications.unread' : 'notifications.read', $n->id) }}" method="POST">
                            @csrf @method('PUT')
                            <button class="btn btn-sm btn-light" title="{{ $n->is_read ? __('Mark as unread') : __('Mark as read') }}" aria-label="{{ $n->is_read ? __('Mark as unread') : __('Mark as read') }}">
                                <i class="bi {{ $n->is_read ? 'bi-arrow-counterclockwise' : 'bi-check' }}" aria-hidden="true"></i>
                            </button>
                        </form>
                        <form action="{{ route('notifications.destroy', $n->id) }}" method="POST" onsubmit="return confirm(@js(__('Remove this notification?')));">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-light text-danger" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}"><i class="bi bi-trash" aria-hidden="true"></i></button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
    @if($notifications->hasPages())
        <div class="p-3 border-top">{{ $notifications->links() }}</div>
    @endif
</div>
@endsection
