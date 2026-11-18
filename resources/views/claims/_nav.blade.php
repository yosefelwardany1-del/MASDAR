{{-- Claims, credit notes and debit notes. $active: claims | credit | debit. --}}
<nav class="app-chips" aria-label="{{ __('Claims & notes') }}">
    <a class="app-chip {{ $active === 'claims' ? 'is-active' : '' }}" href="{{ route('claims.index') }}" @if($active === 'claims') aria-current="page" @endif><i class="bi bi-exclamation-octagon" aria-hidden="true"></i>{{ __('Claims') }}</a>
    <a class="app-chip {{ $active === 'credit' ? 'is-active' : '' }}" href="{{ route('trade-notes.index', ['kind' => 'credit']) }}" @if($active === 'credit') aria-current="page" @endif><i class="bi bi-arrow-return-left" aria-hidden="true"></i>{{ __('Credit notes to clients') }}</a>
    @if(\App\Support\Access\Visibility::canSeeSupplierPrices(auth()->user()))
        <a class="app-chip {{ $active === 'debit' ? 'is-active' : '' }}" href="{{ route('trade-notes.index', ['kind' => 'debit']) }}" @if($active === 'debit') aria-current="page" @endif><i class="bi bi-arrow-return-right" aria-hidden="true"></i>{{ __('Debit notes on factories') }}</a>
    @endif
</nav>
