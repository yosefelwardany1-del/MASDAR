@extends('layouts.app')

@section('title', $user->name)

@php
    use Illuminate\Support\HtmlString;

    $roles = $user->roles->pluck('name')->map(fn ($r) => \App\Security\Roles::label($r));
    $details = [
        __('Roles') => $roles->isNotEmpty()
            ? new HtmlString('<span class="app-rolelist">'.$roles->map(fn ($r) => '<span class="app-pill">'.e($r).'</span>')->join('').'</span>')
            : null,
        __('Phone') => $user->phone ? new HtmlString('<span class="app-ltr">'.e($user->phone).'</span>') : null,
        __('Last sign-in') => $user->last_login ? $user->last_login->diffForHumans() : __('Never'),
    ];
    if ($user->approved_at) {
        $details[__('Approved')] = $user->approved_at->diffForHumans().' — '.$user->approvedBy?->name;
    }
@endphp

@section('content')
<x-page-header :title="$user->name" :back="route('users.index')" :back-label="__('Users')">
    <x-slot:meta>@include('users._status', ['status' => $user->status])</x-slot:meta>
    @can('users.edit')
        <x-slot:actions>
            <a href="{{ route('users.edit', $user) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>

<x-section :title="__('Person')" icon="person">
    <div class="app-person mb-4">
        <x-avatar :name="$user->name" :src="$user->avatarUrl()" :size="64" />
        <div>
            <div class="app-person__name">{{ $user->name }}</div>
            <div class="app-person__sub"><span class="app-ltr">{{ $user->email }}</span></div>
        </div>
    </div>
    <x-detail-list :items="$details" :cols="2" />
</x-section>
@endsection
