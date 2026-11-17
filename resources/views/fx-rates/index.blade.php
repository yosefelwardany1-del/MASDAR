@extends('layouts.app')

@section('title', __('FX rates'))

@php use App\Services\Finance\MoneyFormat; @endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('FX rates') }}</h1>
    @can('payments.edit')
        <a href="{{ route('fx-rates.create', array_filter(['currency' => $currency])) }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('Add a rate') }}</a>
    @endcan
</div>

@include('payments._nav', ['active' => 'rates'])

<p class="text-muted small">{{ __('A rate is how many :book one unit of the currency is worth on that day. A payment takes the latest rate on or before its date unless the rate obtained is typed in.', ['book' => $book]) }}</p>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3">
    <select name="currency" class="form-select" style="max-inline-size:240px" onchange="this.form.submit()">
        <option value="">{{ __('All currencies') }}</option>
        @foreach($currencies as $code => $name)
            <option value="{{ $code }}" @selected($currency === $code)>{{ $code }} — {{ $name }}</option>
        @endforeach
    </select>
    <noscript><button class="btn btn-light">{{ __('Filter') }}</button></noscript>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Date') }}</th><th>{{ __('Currency') }}</th><th class="text-end">{{ __('Rate to :book', ['book' => $book]) }}</th>
                <th>{{ __('Entered by') }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($rates as $rate)
                <tr>
                    <td class="app-num">{{ $rate->rate_date->toDateString() }}</td>
                    <td>{{ $rate->currency }}</td>
                    <td class="text-end app-num">{{ MoneyFormat::rate($rate->rate) }}</td>
                    <td>{{ $rate->creator?->name ?? '—' }}</td>
                    <td class="text-end">
                        @can('payments.edit')
                            <a href="{{ route('fx-rates.edit', $rate) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="app-empty"><i class="bi bi-currency-exchange"></i>{{ __('No rates yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($rates->hasPages())<div class="p-3">{{ $rates->links() }}</div>@endif
</div>
@endsection
