@extends('layouts.app')

@section('title', \App\Security\Roles::label($role->name))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ \App\Security\Roles::label($role->name) }}</h1>
    @if($canEdit)
        <a href="{{ route('roles.edit', $role) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit') }}</a>
    @endif
</div>
<div class="aq-card">
    @include('roles._matrix', ['editable' => false])
</div>
@endsection
