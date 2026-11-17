{{-- A labelled bar: <x-progress :value="7" :max="11" :label="__('Steps done')" tone="done" />
     tone: primary | done | late | due | gold. Over 100% shows full, in the late tone unless told otherwise. --}}
@props(['value' => 0, 'max' => 100, 'label' => null, 'tone' => 'primary', 'caption' => null, 'thin' => false])
@php
    $max = (float) $max > 0 ? (float) $max : 1;
    $pct = (int) round(min(100, max(0, (float) $value / $max * 100)));
    $over = (float) $value > $max;
@endphp
<div {{ $attributes->class(['app-progress', 'app-progress--thin' => $thin, 'app-progress--'.($over ? 'late' : $tone)]) }}>
    @if($label || $caption)
        <div class="app-progress__head">
            @if($label)<span class="app-progress__label">{{ $label }}</span>@endif
            <span class="app-progress__caption app-num">{{ $caption ?? $pct.'%' }}</span>
        </div>
    @endif
    <div class="app-progress__track" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" @if($label) aria-label="{{ $label }}" @endif>
        <span class="app-progress__bar" style="inline-size: {{ $pct }}%"></span>
    </div>
</div>
