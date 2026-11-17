@extends('layouts.app')

@section('title', \App\Security\Roles::label($role->name))

@section('content')
<x-page-header :title="\App\Security\Roles::label($role->name)" :back="route('roles.index')" :back-label="__('Roles and permissions')"
               :subtitle="__('Levels add up: Edit includes View, Approve includes Edit. You can only grant or remove what you hold yourself.')">
    @if($canEdit)
        <x-slot:actions>
            <a href="{{ route('roles.edit', $role) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
        </x-slot:actions>
    @endif
</x-page-header>
<x-section :title="__('Screens and levels')" icon="grid-3x3-gap" flush>
    @include('roles._matrix', ['editable' => false])
</x-section>
@endsection
