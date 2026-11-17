{{-- The ETA flag from ShippingQueries::etaFlag(): $flag = ['late' => N] | ['soon' => N] | []. --}}
@if(isset($flag['late']))
    <span class="app-pill app-pill--late"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ trans_choice('{1} Late by one day|[2,*] Late by :count days', $flag['late'], ['count' => $flag['late']]) }}</span>
@elseif(isset($flag['soon']))
    <span class="app-pill app-pill--due"><i class="bi bi-clock" aria-hidden="true"></i>{{ $flag['soon'] === 0 ? __('Arriving today') : trans_choice('{1} Arriving in one day|[2,*] Arriving in :count days', $flag['soon'], ['count' => $flag['soon']]) }}</span>
@endif
