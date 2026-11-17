@extends('layouts.app')

@section('title', __('Roles and permissions'))

@section('content')
<x-page-header :title="__('Roles and permissions')" :subtitle="__('Ten fixed roles. A user may hold several; their permissions add up.')" />
<x-section :title="__('Roles')" icon="shield-lock" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Role') }}</th><th scope="col" class="text-end">{{ __('Users') }}</th><th scope="col" class="text-end">{{ __('Permissions') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th></tr></thead>
            <tbody>
            @foreach($roles as $role)
                <tr>
                    <td>
                        <a href="{{ route('roles.show', $role) }}" class="fw-semibold">{{ \App\Security\Roles::label($role->name) }}</a>
                        @if(in_array($role->name, $locked, true))<x-status tone="neutral" icon="lock" class="ms-1">{{ __('Locked') }}</x-status>@endif
                    </td>
                    <td class="text-end"><span class="app-num">{{ $role->users_count }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $role->permissions()->count() }}</span></td>
                    <td class="text-end">
                        <span class="app-rowactions">
                            <a href="{{ route('roles.show', $role) }}" class="btn btn-sm btn-light">{{ __('View') }}</a>
                            @if($canEdit($role))
                                <a href="{{ route('roles.edit', $role) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
                            @endif
                        </span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-section>
@endsection
