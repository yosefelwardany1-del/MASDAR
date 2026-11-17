{{-- A headline figure. <x-kpi :label="__('Paid')" :value="$money" icon="cash-coin" tone="done" :hint="..." :href="..." /> --}}
@props(['label', 'value', 'icon' => null, 'tone' => null, 'hint' => null, 'href' => null])
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->class(['app-card app-stat app-kpi h-100', 'text-decoration-none' => $href, 'app-stat--'.$tone => $tone]) }}>
    <span class="app-stat__top">
        @if($icon)<span class="app-stat__icon"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span>@endif
        @if($href)<i class="bi bi-arrow-right app-stat__go" aria-hidden="true"></i>@endif
    </span>
    <span class="app-stat__value app-kpi__value">{{ $value }}</span>
    <span class="app-stat__label">{{ $label }}</span>
    @if($hint)<span class="app-kpi__hint">{{ $hint }}</span>@endif
</{{ $tag }}>
