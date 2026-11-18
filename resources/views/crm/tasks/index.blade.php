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
    // Open tasks read as a day plan: what is late, what is for today, what comes after.
    $groups = $filter === 'done'
        ? ['done' => [__('Done'), 'check2-all', $tasks->getCollection()]]
        : [
            'late' => [__('Overdue'), 'exclamation-triangle', $tasks->getCollection()->filter->isOverdue()],
            'today' => [__('Today'), 'clock', $tasks->getCollection()->filter->isDueToday()],
            'later' => [__('Coming up'), 'calendar-event', $tasks->getCollection()->reject(fn ($t) => $t->isOverdue() || $t->isDueToday())],
        ];
@endphp

@section('content')
<x-page-header :title="__('Follow-up tasks')" :subtitle="__('Calls to return, samples to chase, payments to ask for: one list, soonest first.')">
    @if($overdueCount > 0)
        <x-slot:meta>
            <span class="app-pill app-pill--late"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ trans_choice('{1} One of your tasks is overdue.|[2,*] :count of your tasks are overdue.', $overdueCount, ['count' => $overdueCount]) }}</span>
        </x-slot:meta>
    @endif
</x-page-header>

@include('crm._nav', ['active' => 'tasks'])

<div class="app-tasks">
    <div class="app-tasks__main">
        <nav class="app-doc-chips" aria-label="{{ __('Filter') }}">
            @foreach(['mine' => ['person', __('My open tasks')], 'all' => ['people', __('All open tasks')], 'done' => ['check2-all', __('Done')]] as $value => [$icon, $label])
                <a class="app-doc-chip {{ $filter === $value ? 'is-active' : '' }}" href="{{ route('crm.tasks.index', ['filter' => $value]) }}" @if($filter === $value) aria-current="page" @endif><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</a>
            @endforeach
        </nav>
        @error('task')<div class="alert alert-danger py-2" role="alert">{{ $message }}</div>@enderror

        @if($tasks->isEmpty())
            <div class="app-card">
                <x-empty icon="check2-square" :title="$filter === 'done' ? __('No finished tasks yet.') : __('Nothing to follow up.')"
                         :text="$filter === 'done' ? null : __('A next step with a due date also becomes a task for you.')" />
            </div>
        @else
            @foreach($groups as $key => [$title, $icon, $rows])
                @continue($rows->isEmpty())
                <section class="app-card app-tasks__group app-tasks__group--{{ $key }}" aria-labelledby="tasks-{{ $key }}">
                    <h2 class="app-card__head border-bottom app-tasks__head" id="tasks-{{ $key }}">
                        <i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $title }}
                        <span class="app-ptabs__count app-num">{{ $rows->count() }}</span>
                    </h2>
                    <ul class="app-tasklist">
                        @foreach($rows as $task)
                            <li class="app-task {{ $task->isOverdue() ? 'is-overdue' : '' }} {{ $task->isDone() ? 'is-done' : '' }}">
                                @if($canEdit)
                                    @if($task->isDone())
                                        <form method="POST" action="{{ route('crm.tasks.reopen', $task) }}" class="app-task__check">@csrf
                                            <button class="app-task__done is-reopen" title="{{ __('Reopen') }}"><i class="bi bi-check2" aria-hidden="true"></i><span class="visually-hidden">{{ __('Reopen') }}: {{ $task->title }}</span></button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('crm.tasks.complete', $task) }}" class="app-task__check">@csrf
                                            <button class="app-task__done" title="{{ __('Mark done') }}"><i class="bi bi-check2" aria-hidden="true"></i><span class="visually-hidden">{{ __('Mark done') }}: {{ $task->title }}</span></button>
                                        </form>
                                    @endif
                                @endif
                                <div class="app-task__main">
                                    <div class="app-task__title" dir="auto">{{ $task->title }}</div>
                                    @if($task->notes)<div class="app-task__notes" dir="auto">{{ \Illuminate\Support\Str::limit($task->notes, 120) }}</div>@endif
                                    <div class="app-task__meta">
                                        @include('crm.tasks._due')
                                        @if($task->party)
                                            @php $href = $partyLink($task->party); @endphp
                                            <span class="app-task__party"><i class="bi bi-building" aria-hidden="true"></i>
                                                @if($href)<a href="{{ $href }}"><bdi>{{ $task->party->name }}</bdi></a>@else<bdi>{{ $task->party->name }}</bdi>@endif
                                                <span class="app-doc-code">{{ $task->party->code }}</span></span>
                                        @endif
                                        <span><i class="bi bi-person" aria-hidden="true"></i> <bdi>{{ $task->assignee?->name }}</bdi></span>
                                        @if($filter === 'done' && $task->done_at)
                                            <span><i class="bi bi-check2-circle" aria-hidden="true"></i> <span class="app-num">{{ $task->done_at->format('Y-m-d H:i') }}</span>@if($task->doneBy) · {{ $task->doneBy->name }}@endif</span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @endif
        @if($tasks->hasPages())<div class="mt-3">{{ $tasks->links() }}</div>@endif
    </div>

    @if($canEdit)
        <aside class="app-tasks__aside">
            <section class="app-card" aria-labelledby="new-task-head">
                <h2 class="app-card__head border-bottom" id="new-task-head"><i class="bi bi-plus-square" aria-hidden="true"></i>{{ __('New task') }}</h2>
                <div class="app-section__body">
                    @include('crm.tasks._form', ['party' => null, 'parties' => $parties, 'users' => $users])
                </div>
            </section>
        </aside>
    @endif
</div>
@endsection
