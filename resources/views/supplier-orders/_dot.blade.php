{{-- One step's state as a compact dot for the board (a local variant of .app-pill: the icon shows
     the state, the word and the date are in the tooltip and for screen readers).
     Requires $state (App\Services\SupplierOrders\StepState) and $step; optional $next (true for the step that comes next). --}}
@php
    $tip = $step->displayName().' — '.$state->label();
    if ($state->isSettled() && $state->latest) {
        $tip .= ' · '.$state->latest->happened_on->format('Y-m-d').($state->latest->note ? ': '.$state->latest->note : '');
    } elseif ($state->dueOn) {
        $tip .= ' · '.__('due :date', ['date' => $state->dueOn->format('Y-m-d')]);
    }
    $icon = match ($state->state) {
        'done' => 'bi-check-lg',
        'skipped' => 'bi-dash-lg',
        'late' => 'bi-exclamation-lg',
        'due' => 'bi-clock',
        default => null,
    };
@endphp
<span class="app-pill {{ $state->pillClass() }} app-fu-dot @if(! empty($next)) is-next @endif" title="{{ $tip }}" data-bs-toggle="tooltip">@if($icon)<i class="bi {{ $icon }}" aria-hidden="true"></i>@endif<span class="visually-hidden">{{ $tip }}@if(! empty($next)) ({{ __('next step') }})@endif</span></span>
