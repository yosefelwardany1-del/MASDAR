{{-- A sequence of steps (order steps, container statuses), in reading direction.
     $steps: list of ['label' => string, 'state' => done|current|late|due|todo|skip, 'date' => ?string, 'hint' => ?string, 'word' => ?string (instead of the state's word)]
     compact: dots only, labels as tooltips (for board rows).
     keepRow: stay a row on phones too (for a short stepper, e.g. four phases). --}}
@props(['steps' => [], 'compact' => false, 'label' => null, 'keepRow' => false])
@php
    $icon = ['done' => 'check-lg', 'current' => 'circle-fill', 'late' => 'exclamation', 'due' => 'clock', 'skip' => 'dash', 'todo' => ''];
    $words = ['done' => __('Done'), 'current' => __('Now'), 'late' => __('Late'), 'due' => __('Due soon'), 'skip' => __('Not applicable'), 'todo' => __('Not done yet')];
@endphp
<ol {{ $attributes->class(['app-stepper', 'app-stepper--compact' => $compact, 'app-stepper--row' => $keepRow]) }} @if($label) aria-label="{{ $label }}" @endif>
    @foreach($steps as $i => $step)
        @php $state = $step['state'] ?? 'todo'; $word = $step['word'] ?? $words[$state]; $tip = trim($step['label'].' — '.$word.(! empty($step['date']) ? ' · '.$step['date'] : '')); @endphp
        <li class="app-stepper__step is-{{ $state }}" @if($compact) title="{{ $tip }}" @endif>
            <span class="app-stepper__dot" aria-hidden="true">@if($icon[$state] ?? '')<i class="bi bi-{{ $icon[$state] }}"></i>@else{{ $i + 1 }}@endif</span>
            @if($compact)
                <span class="visually-hidden">{{ $tip }}</span>
            @else
                <span class="app-stepper__text">
                    <span class="app-stepper__label">{{ $step['label'] }}</span>
                    <span class="app-stepper__sub">{{ $word }}@if(! empty($step['date'])) · <span class="app-num">{{ $step['date'] }}</span>@endif</span>
                    @if(! empty($step['hint']))<span class="app-stepper__hint">{{ $step['hint'] }}</span>@endif
                </span>
            @endif
        </li>
    @endforeach
</ol>
