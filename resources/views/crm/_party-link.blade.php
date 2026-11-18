{{-- A party's name, linked to the first of its screens the user may open. $party. --}}
@php
    $href = null;
    foreach ($party->kinds() as $k) {
        if (Gate::allows($k->screen().'.view')) { $href = route($k->routePrefix().'.show', $party); break; }
    }
@endphp
@if($href)<a href="{{ $href }}" class="{{ $class ?? '' }}"><bdi>{{ $party->name }}</bdi></a>@else<bdi class="{{ $class ?? '' }}">{{ $party->name }}</bdi>@endif
