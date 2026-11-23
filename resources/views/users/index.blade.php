@extends('layouts.app')

@section('title', __('Users'))

@section('content')
<x-page-header :title="__('Users')" :subtitle="__('Who can sign in, and with which roles.')">
    @can('users.edit')
        <x-slot:actions>
            <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="bi bi-person-plus" aria-hidden="true"></i>{{ __('New user') }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>

@if($pendingCount > 0 && $status !== 'pending')
    <div class="app-callout" role="status">
        <i class="bi bi-person-exclamation" aria-hidden="true"></i>
        <span class="app-callout__text">{{ trans_choice('{1} One account is waiting for a role.|[2,*] :count accounts are waiting for a role.', $pendingCount, ['count' => $pendingCount]) }}</span>
        <a href="{{ route('users.index', ['status' => 'pending']) }}" class="btn btn-sm btn-warning">{{ __('Review') }}</a>
    </div>
@endif

<form method="GET" class="app-filterbar" role="search" aria-label="{{ __('Users') }}">
    <div class="app-filterbar__field app-filterbar__field--wide">
        <label class="form-label" for="u-search">{{ __('Search') }}</label>
        <input type="search" name="search" id="u-search" value="{{ $search }}" class="form-control form-control-sm" placeholder="{{ __('Search by name or e-mail') }}">
    </div>
    <div class="app-filterbar__field">
        <label class="form-label" for="u-status">{{ __('Status') }}</label>
        <select name="status" id="u-status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">{{ __('All statuses') }}</option>
            @foreach(['active' => __('Active'), 'inactive' => __('Inactive'), 'pending' => __('Awaiting approval')] as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__actions">
        <button class="btn btn-sm btn-primary"><i class="bi bi-search" aria-hidden="true"></i>{{ __('Search') }}</button>
        @if($search || $status)<a href="{{ route('users.index') }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>@endif
    </div>
</form>

<x-section :title="__('Users')" icon="people" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Roles') }}</th>
                <th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Last sign-in') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse($users as $u)
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <x-avatar :name="$u->name" :src="$u->avatarUrl()" :size="32" />
                            <div class="min-w-0">
                                <a href="{{ route('users.show', $u) }}" class="fw-semibold">{{ $u->name }}</a>
                                <div class="small text-muted"><span class="app-ltr">{{ $u->email }}</span></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        @if($u->roles->isNotEmpty())
                            <span class="app-rolelist">@foreach($u->roles as $r)<span class="app-pill">{{ \App\Security\Roles::label($r->name) }}</span>@endforeach</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>@include('users._status', ['status' => $u->status])</td>
                    <td class="small">{{ $u->last_login ? $u->last_login->diffForHumans() : __('Never') }}</td>
                    <td class="text-end">
                        <span class="app-rowactions">
                            <a href="{{ route('users.show', $u) }}" class="btn btn-sm btn-light" title="{{ __('View') }}" aria-label="{{ __('View') }}: {{ $u->name }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
                            @can('users.edit')
                                {{-- B24: Edit only where it opens; yourself on My profile. --}}
                                @if(auth()->user()->is($u))
                                    <a href="{{ route('profile') }}" class="btn btn-sm btn-light" title="{{ __('My profile') }}" aria-label="{{ __('My profile') }}"><i class="bi bi-person" aria-hidden="true"></i></a>
                                @elseif($guard->canManageUser(auth()->user(), $u))
                                    <a href="{{ route('users.edit', $u) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}: {{ $u->name }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                @endif
                            @endcan
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><x-empty icon="people" :title="__('No users found.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($users->hasPages())<div class="p-3 border-top">{{ $users->links() }}</div>@endif
</x-section>
@endsection
