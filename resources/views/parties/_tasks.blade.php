{{-- A party's open follow-up tasks, soonest first, with "done" buttons and a form for a new one (crm.edit). --}}
@php $canCrm = auth()->user()->can('crm.edit'); @endphp
<section class="app-card h-100" id="tasks" aria-labelledby="tasks-head">
    <div class="app-card__head border-bottom" id="tasks-head">
        <i class="bi bi-check2-square" aria-hidden="true"></i>{{ __('Open tasks') }}
        <span class="app-ptabs__count app-num">{{ $openTasks->count() }}</span>
    </div>
    @error('task')<div class="alert alert-danger py-2 small m-3 mb-0" role="alert">{{ $message }}</div>@enderror
    @if($openTasks->isEmpty())
        <div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('No open tasks.') }}</div>
    @else
        <ul class="app-tasklist">
            @foreach($openTasks as $task)
                <li class="app-task {{ $task->isOverdue() ? 'is-overdue' : '' }}">
                    @if($canCrm)
                        <form method="POST" action="{{ route('crm.tasks.complete', $task) }}" class="app-task__check">
                            @csrf<button class="app-task__done" title="{{ __('Mark done') }}"><i class="bi bi-check2" aria-hidden="true"></i><span class="visually-hidden">{{ __('Mark done') }}: {{ $task->title }}</span></button>
                        </form>
                    @endif
                    <div class="app-task__main">
                        <div class="app-task__title" dir="auto">{{ $task->title }}</div>
                        @if($task->notes)<div class="app-task__notes" dir="auto">{{ $task->notes }}</div>@endif
                        <div class="app-task__meta">@include('crm.tasks._due')<span><i class="bi bi-person" aria-hidden="true"></i> <bdi>{{ $task->assignee?->name }}</bdi></span></div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
    @if($canCrm)
        <details class="app-adder" @if($errors->getBag('task')->any()) open @endif>
            <summary class="app-adder__summary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add task') }}</summary>
            <div class="app-adder__body">@include('crm.tasks._form', ['party' => $party, 'users' => $users])</div>
        </details>
    @endif
</section>
