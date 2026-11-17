{{-- A PI's status pill: an icon and the word, the colour only confirms. Requires $pi. --}}
@php
    [$tone, $icon] = [
        'draft' => ['', 'pencil'],
        'issued' => ['app-pill--info', 'send'],
        'accepted' => ['app-pill--due', 'hand-thumbs-up'],
        'confirmed' => ['app-pill--done', 'check-circle'],
        'cancelled' => ['app-pill--late', 'x-circle'],
        'superseded' => ['app-pill--skip', 'dash-circle'],
    ][$pi->status] ?? ['', 'circle'];
@endphp
<span class="app-pill {{ $tone }}"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $pi->statusLabel() }}</span>
