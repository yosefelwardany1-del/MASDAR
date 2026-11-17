{{-- The top of every screen: what it is, where it sits, what can be done here.
     <x-page-header :title="__('Order SO-2026-0001')" :subtitle="..." :back="route('...')" :back-label="__('Order follow-up')">
         <x-slot:meta><x-status tone="done" icon="check-circle">…</x-status></x-slot:meta>
         <x-slot:actions><a class="btn btn-primary">…</a></x-slot:actions>
     </x-page-header> --}}
@props(['title', 'subtitle' => null, 'back' => null, 'backLabel' => null, 'eyebrow' => null])
@php $rtl = \App\Http\Middleware\SetUserLocale::isRtl(); @endphp
<header {{ $attributes->class('app-pagehead') }}>
    <div class="app-pagehead__main">
        @if($back)
            <a href="{{ $back }}" class="app-pagehead__back"><i class="bi {{ $rtl ? 'bi-arrow-right' : 'bi-arrow-left' }}" aria-hidden="true"></i>{{ $backLabel ?? __('Back') }}</a>
        @elseif($eyebrow)
            <div class="app-pagehead__eyebrow">{{ $eyebrow }}</div>
        @endif
        <div class="app-pagehead__titlerow">
            <h1 class="app-pagehead__title">{{ $title }}</h1>
            @isset($meta)<div class="app-pagehead__meta">{{ $meta }}</div>@endisset
        </div>
        @if($subtitle)<p class="app-pagehead__subtitle">{{ $subtitle }}</p>@endif
    </div>
    @isset($actions)<div class="app-pagehead__actions">{{ $actions }}</div>@endisset
</header>
