@extends('layouts.app')

@section('title', __('Roles and permissions'))

@section('content')
<h1 class="h4 mb-1">{{ __('Roles and permissions') }}</h1>
<p class="text-muted mb-3">{{ __('Ten fixed roles. A user may hold several; their permissions add up.') }}</p>
<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>{{ __('Role') }}</th><th>{{ __('Users') }}</th><th>{{ __('Permissions') }}</th><th></th></tr></thead>
            <tbody>
            @foreach($roles as $role)
                <tr>
                    <td>
                        {{ \App\Security\Roles::label($role->name) }}
                        @if(in_array($role->name, $locked, true))<span class="badge bg-secondary-subtle text-secondary-emphasis ms-1"><i class="bi bi-lock"></i> {{ __('Locked') }}</span>@endif
                    </td>
                    <td>{{ $role->users_count }}</td>
                    <td>{{ $role->permissions()->count() }}</td>
                    <td class="text-end">
                        <a href="{{ route('roles.show', $role) }}" class="btn btn-sm btn-light">{{ __('View') }}</a>
                        @if($canEdit($role))
                            <a href="{{ route('roles.edit', $role) }}" class="btn btn-sm btn-light">{{ __('Edit') }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
