{{-- A task's due date as a pill: done, overdue, due today, or upcoming. Param: $task. --}}
@if($task->isDone())
    <span class="app-pill app-pill--done"><i class="bi bi-check2"></i><span class="app-num">{{ $task->due_on->format('Y-m-d') }}</span></span>
@elseif($task->isOverdue())
    <span class="app-pill app-pill--late" title="{{ __('Overdue') }}"><i class="bi bi-exclamation-circle"></i><span class="app-num">{{ $task->due_on->format('Y-m-d') }}</span></span>
@elseif($task->isDueToday())
    <span class="app-pill app-pill--due">{{ __('Today') }}</span>
@else
    <span class="app-pill"><span class="app-num">{{ $task->due_on->format('Y-m-d') }}</span></span>
@endif
