{{-- A status label: an icon and a word, the colour only confirms (never colour alone).
     tone: done | late | due | info | skip | neutral. <x-status tone="late" icon="exclamation-triangle">Late</x-status> --}}
@props(['tone' => 'neutral', 'icon' => null])
@php
    $icons = ['done' => 'check-circle', 'late' => 'exclamation-triangle', 'due' => 'clock', 'info' => 'info-circle', 'skip' => 'dash-circle', 'neutral' => 'circle'];
    $class = ['done' => 'app-pill--done', 'late' => 'app-pill--late', 'due' => 'app-pill--due', 'info' => 'app-pill--info', 'skip' => 'app-pill--skip'][$tone] ?? '';
@endphp
<span {{ $attributes->class(['app-pill', $class]) }}><i class="bi bi-{{ $icon ?? ($icons[$tone] ?? 'circle') }}" aria-hidden="true"></i>{{ $slot }}</span>
