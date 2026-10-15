@extends('layouts.app')

@section('title', __('Users'))

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Users') }}</h1>
    @can('users.edit')
        <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>{{ __('New user') }}</a>
    @endcan
</div>

@if($pendingCount > 0 && $status !== 'pending')
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <span>{{ trans_choice('{1} One account is waiting for a role.|[2,*] :count accounts are waiting for a role.', $pendingCount, ['count' => $pendingCount]) }}</span>
        <a href="{{ route('users.index', ['status' => 'pending']) }}" class="btn btn-sm btn-warning">{{ __('Review') }}</a>
    </div>
@endif

<form method="GET" class="d-flex flex-wrap gap-2 mb-3">
    <input type="search" name="search" value="{{ $search }}" class="form-control" style="max-inline-size:280px" placeholder="{{ __('Search by name or e-mail') }}">
    <select name="status" class="form-select" style="max-inline-size:200px" onchange="this.form.submit()">
        <option value="">{{ __('All statuses') }}</option>
        @foreach(['active' => __('Active'), 'inactive' => __('Inactive'), 'pending' => __('Awaiting approval')] as $value => $label)
            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <button class="btn btn-light">{{ __('Search') }}</button>
</form>

<div class="aq-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Name') }}</th><th>{{ __('E-mail') }}</th><th>{{ __('Roles') }}</th>
                <th>{{ __('Status') }}</th><th>{{ __('Last sign-in') }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($users as $u)
                <tr>
                    <td><div class="d-flex align-items-center gap-2"><x-avatar :name="$u->name" :src="$u->avatarUrl()" :size="30" />{{ $u->name }}</div></td>
                    <td><span class="aq-ltr">{{ $u->email }}</span></td>
                    <td>{{ $u->roles->pluck('name')->map(fn ($r) => \App\Security\Roles::label($r))->join('، ') ?: '—' }}</td>
                    <td>@include('users._status', ['status' => $u->status])</td>
                    <td>{{ $u->last_login ? $u->last_login->diffForHumans() : __('Never') }}</td>
                    <td class="text-end">
                        <a href="{{ route('users.show', $u) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-eye"></i></a>
                        @can('users.edit')
                            <a href="{{ route('users.edit', $u) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="aq-empty">{{ __('No users found.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($users->hasPages())<div class="p-3">{{ $users->links() }}</div>@endif
</div>
@endsection
