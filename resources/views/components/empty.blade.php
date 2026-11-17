{{-- An empty state that says what will appear and how to add the first one. --}}
@props(['icon' => 'inbox', 'title', 'text' => null])
<div {{ $attributes->class('app-emptystate') }}>
    <span class="app-emptystate__icon"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span>
    <div class="app-emptystate__title">{{ $title }}</div>
    @if($text)<p class="app-emptystate__text">{{ $text }}</p>@endif
    @isset($action)<div class="app-emptystate__action">{{ $action }}</div>@endisset
</div>
