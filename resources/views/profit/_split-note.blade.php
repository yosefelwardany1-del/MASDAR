{{-- How a shared container's costs are split (setting container_cost_split, spec Q-C3). --}}
@php
    $splitLabel = ['cbm' => __('by CBM loaded'), 'kg' => __('by weight loaded'), 'value' => __('by the value of the goods loaded'), 'cartons' => __('by cartons loaded')][$split] ?? $split;
@endphp
<p class="app-note mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('In :book. Sales and goods cost are converted at the rate of their document\'s date. A shared container\'s costs (its shipping bill and the expenses paid against it) are split :split; this is a setting on Lists & options.', ['book' => $book, 'split' => $splitLabel]) }}</span></p>
