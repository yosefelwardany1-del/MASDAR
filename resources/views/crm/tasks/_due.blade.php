{{-- A task's due date as a pill: done, overdue, due today, or upcoming. An icon and a word or a
     date in each, so the colour only confirms. Param: $task. --}}
@if($task->isDone())
    <span class="app-pill app-pill--done"><i class="bi bi-check2" aria-hidden="true"></i><span class="visually-hidden">{{ __('Done') }}:</span><span class="app-num">{{ $task->due_on->format('Y-m-d') }}</span></span>
@elseif($task->isOverdue())
    <span class="app-pill app-pill--late" title="{{ __('Overdue') }}"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span class="visually-hidden">{{ __('Overdue') }}:</span><span class="app-num">{{ $task->due_on->format('Y-m-d') }}</span></span>
@elseif($task->isDueToday())
    <span class="app-pill app-pill--due"><i class="bi bi-clock" aria-hidden="true"></i>{{ __('Today') }}</span>
@else
    <span class="app-pill"><i class="bi bi-calendar-event" aria-hidden="true"></i><span class="app-num">{{ $task->due_on->format('Y-m-d') }}</span></span>
@endif
