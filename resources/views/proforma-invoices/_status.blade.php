{{-- A PI's status pill. Requires $pi. --}}
@php
    $tone = ['issued' => 'app-pill--due', 'accepted' => 'app-pill--due', 'confirmed' => 'app-pill--done', 'cancelled' => 'app-pill--late', 'superseded' => 'app-pill--skip'][$pi->status] ?? '';
@endphp
<span class="app-pill {{ $tone }}">{{ $pi->statusLabel() }}</span>
