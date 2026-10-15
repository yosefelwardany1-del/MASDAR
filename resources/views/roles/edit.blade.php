@extends('layouts.app')

@section('title', __('Edit role'))

@section('content')
<h1 class="h4 mb-1">{{ \App\Security\Roles::label($role->name) }}</h1>
<p class="text-muted mb-3">{{ __('Levels add up: Edit includes View, Approve includes Edit. You can only grant or remove what you hold yourself.') }}</p>
<form method="POST" action="{{ route('roles.update', $role) }}">
    @csrf @method('PUT')
    <div class="aq-card">
        @include('roles._matrix', ['editable' => true])
    </div>
    <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('roles.show', $role) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
