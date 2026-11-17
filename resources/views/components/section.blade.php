{{-- A titled card. <x-section :title="__('Lines')" icon="list-ul"><x-slot:actions>…</x-slot:actions> … </x-section>
     flush: no inner padding (for a table or a list that owns its edges). --}}
@props(['title' => null, 'icon' => null, 'flush' => false, 'subtitle' => null])
<section {{ $attributes->class('app-card app-section') }}>
    @if($title || isset($actions))
        <div class="app-card__head border-bottom">
            @if($icon)<i class="bi bi-{{ $icon }}" aria-hidden="true"></i>@endif
            <span class="app-section__title">{{ $title }}@if($subtitle)<span class="app-section__subtitle">{{ $subtitle }}</span>@endif</span>
            @isset($actions)<div class="app-section__actions ms-auto">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div class="{{ $flush ? '' : 'app-section__body' }}">{{ $slot }}</div>
</section>
