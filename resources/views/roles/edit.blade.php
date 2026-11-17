@extends('layouts.app')

@section('title', __('Edit role'))

@section('content')
<x-page-header :title="\App\Security\Roles::label($role->name)" :back="route('roles.show', $role)" :back-label="\App\Security\Roles::label($role->name)"
               :subtitle="__('Levels add up: Edit includes View, Approve includes Edit. You can only grant or remove what you hold yourself.')" />
<form method="POST" action="{{ route('roles.update', $role) }}" class="app-form">
    @csrf @method('PUT')
    @include('configuration._form-errors')
    <x-section :title="__('Screens and levels')" icon="grid-3x3-gap" flush>
        @include('roles._matrix', ['editable' => true])
    </x-section>
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('roles.show', $role) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
