{{-- A party's open follow-up tasks, with "done" buttons and a form for a new one (crm.edit). --}}
@php $canCrm = auth()->user()->can('crm.edit'); @endphp
<div class="app-card p-4" id="tasks">
    <h2 class="h6 mb-3"><i class="bi bi-check2-square me-1"></i>{{ __('Open tasks') }} <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $openTasks->count() }}</span></h2>
    @error('task')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
    <ul class="list-unstyled mb-0">
        @forelse($openTasks as $task)
            <li class="d-flex align-items-start gap-2 py-2 border-bottom">
                <div class="flex-grow-1">
                    <div>{{ $task->title }}</div>
                    <div class="small text-muted">@include('crm.tasks._due') · {{ $task->assignee?->name }}</div>
                    @if($task->notes)<div class="small text-muted mt-1">{{ $task->notes }}</div>@endif
                </div>
                @if($canCrm)
                    <form method="POST" action="{{ route('crm.tasks.complete', $task) }}">
                        @csrf<button class="btn btn-sm btn-light" title="{{ __('Mark done') }}"><i class="bi bi-check2"></i></button>
                    </form>
                @endif
            </li>
        @empty
            <li class="text-muted small">{{ __('No open tasks.') }}</li>
        @endforelse
    </ul>
    @if($canCrm)
        <details class="mt-3" @if($errors->getBag('task')->any()) open @endif>
            <summary class="btn btn-sm btn-light"><i class="bi bi-plus-lg me-1"></i>{{ __('Add task') }}</summary>
            <div class="mt-3">@include('crm.tasks._form', ['party' => $party, 'users' => $users])</div>
        </details>
    @endif
</div>
