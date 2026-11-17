@extends('layouts.app')

@section('title', __('Follow-up tasks'))

@php
    use Illuminate\Support\Facades\Gate;
    $canEdit = auth()->user()->can('crm.edit');
    // A task's party links to the first of its screens the user may open.
    $partyLink = function ($party) {
        foreach ($party->kinds() as $k) {
            if (Gate::allows($k->screen().'.view')) {
                return route($k->routePrefix().'.show', $party);
            }
        }

        return null;
    };
@endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Follow-up tasks') }}</h1>
    @if($overdueCount > 0)
        <span class="app-pill app-pill--late">{{ trans_choice('{1} One of your tasks is overdue.|[2,*] :count of your tasks are overdue.', $overdueCount, ['count' => $overdueCount]) }}</span>
    @endif
</div>

<div class="row g-3">
    <div class="{{ $canEdit ? 'col-lg-8' : 'col-12' }}">
        <ul class="nav nav-pills mb-3">
            @foreach(['mine' => __('My open tasks'), 'all' => __('All open tasks'), 'done' => __('Done')] as $value => $label)
                <li class="nav-item"><a class="nav-link {{ $filter === $value ? 'active' : '' }}" href="{{ route('crm.tasks.index', ['filter' => $value]) }}">{{ $label }}</a></li>
            @endforeach
        </ul>
        @error('task')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

        <div class="app-card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('Due') }}</th><th>{{ __('Task') }}</th><th>{{ __('About') }}</th><th>{{ __('Assigned to') }}</th>
                        @if($filter === 'done')<th>{{ __('Done') }}</th>@endif
                        @if($canEdit)<th></th>@endif
                    </tr></thead>
                    <tbody>
                    @forelse($tasks as $task)
                        <tr>
                            <td>@include('crm.tasks._due')</td>
                            <td>
                                <div>{{ $task->title }}</div>
                                @if($task->notes)<div class="small text-muted">{{ \Illuminate\Support\Str::limit($task->notes, 120) }}</div>@endif
                            </td>
                            <td>
                                @if($task->party)
                                    @php $href = $partyLink($task->party); @endphp
                                    @if($href)<a href="{{ $href }}">{{ $task->party->name }}</a>@else{{ $task->party->name }}@endif
                                    <div class="small text-muted app-num">{{ $task->party->code }}</div>
                                @else — @endif
                            </td>
                            <td>{{ $task->assignee?->name }}</td>
                            @if($filter === 'done')<td class="small"><span class="app-num">{{ $task->done_at?->format('Y-m-d H:i') }}</span><div class="text-muted">{{ $task->doneBy?->name }}</div></td>@endif
                            @if($canEdit)
                                <td class="text-end">
                                    @if($task->isDone())
                                        <form method="POST" action="{{ route('crm.tasks.reopen', $task) }}">@csrf
                                            <button class="btn btn-sm btn-light" title="{{ __('Reopen') }}"><i class="bi bi-arrow-counterclockwise"></i></button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('crm.tasks.complete', $task) }}">@csrf
                                            <button class="btn btn-sm btn-light" title="{{ __('Mark done') }}"><i class="bi bi-check2"></i></button>
                                        </form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" class="app-empty"><i class="bi bi-check2-square"></i>{{ $filter === 'done' ? __('No finished tasks yet.') : __('Nothing to follow up.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($tasks->hasPages())<div class="p-3">{{ $tasks->links() }}</div>@endif
        </div>
    </div>

    @if($canEdit)
        <div class="col-lg-4">
            <div class="app-card p-4">
                <h2 class="h6 mb-3">{{ __('New task') }}</h2>
                @include('crm.tasks._form', ['party' => null, 'parties' => $parties, 'users' => $users])
            </div>
        </div>
    @endif
</div>
@endsection
