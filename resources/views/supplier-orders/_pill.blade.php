{{-- One step's state as an .app-pill. Requires $state (App\Services\SupplierOrders\StepState). --}}
@php
    $date = $state->date();
    $tip = $state->label();
    if ($state->isSettled() && $state->latest) {
        $tip .= ' — '.$state->latest->happened_on->format('Y-m-d').($state->latest->note ? ': '.$state->latest->displayNote() : '');
    } elseif ($state->dueOn) {
        $tip .= ' — '.__('due :date', ['date' => $state->dueOn->format('Y-m-d')]);
    }
    $icon = match ($state->state) {
        'done' => 'bi-check2',
        'skipped' => 'bi-dash',
        'late' => 'bi-exclamation-triangle',
        'due' => 'bi-clock',
        default => null,
    };
@endphp
<span class="app-pill {{ $state->pillClass() }}" title="{{ $tip }}">
    @if($icon)<i class="bi {{ $icon }}" aria-hidden="true"></i>@endif
    @if($state->state === 'skipped')
        {{ __('N/A') }}
    @elseif($date)
        <span class="app-num">{{ $date->format('Y-m-d') }}</span>
    @else
        —
    @endif
    <span class="visually-hidden">{{ $state->label() }}</span>
</span>
