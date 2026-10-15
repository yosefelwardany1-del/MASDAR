@extends('layouts.app')

@section('title', $user->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ $user->name }}</h1>
    @can('users.edit')
        <a href="{{ route('users.edit', $user) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit') }}</a>
    @endcan
</div>
<div class="aq-card p-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <x-avatar :name="$user->name" :src="$user->avatarUrl()" :size="64" />
        <div>
            <div class="fw-semibold">{{ $user->name }}</div>
            <div class="text-muted"><span class="aq-ltr">{{ $user->email }}</span></div>
        </div>
    </div>
    <dl class="row mb-0">
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Status') }}</dt><dd class="col-sm-9">@include('users._status', ['status' => $user->status])</dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Roles') }}</dt><dd class="col-sm-9">{{ $user->roles->pluck('name')->map(fn ($r) => \App\Security\Roles::label($r))->join('، ') ?: '—' }}</dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Phone') }}</dt><dd class="col-sm-9"><span class="aq-ltr">{{ $user->phone ?: '—' }}</span></dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Last sign-in') }}</dt><dd class="col-sm-9">{{ $user->last_login ? $user->last_login->diffForHumans() : __('Never') }}</dd>
        @if($user->approved_at)
            <dt class="col-sm-3 text-muted fw-normal">{{ __('Approved') }}</dt><dd class="col-sm-9">{{ $user->approved_at->diffForHumans() }} — {{ $user->approvedBy?->name }}</dd>
        @endif
    </dl>
</div>
@endsection
