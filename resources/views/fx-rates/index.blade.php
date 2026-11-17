@extends('layouts.app')

@section('title', __('FX rates'))

@php use App\Services\Finance\MoneyFormat; @endphp

@section('content')
<x-page-header :title="__('FX rates')"
               :subtitle="__('A rate is how many :book one unit of the currency is worth on that day. A payment takes the latest rate on or before its date unless the rate obtained is typed in.', ['book' => $book])">
    @can('payments.edit')
        <x-slot:actions>
            <a href="{{ route('fx-rates.create', array_filter(['currency' => $currency])) }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add a rate') }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>

@include('payments._nav', ['active' => 'rates'])

@if($latest->isNotEmpty())
    <div class="row g-3 mb-4 app-kpis">
        @foreach($latest as $r)
            @php
                $inverse = bccomp((string) $r->rate, '0', 6) > 0 ? bcdiv('1', (string) $r->rate, 4) : null;
                $value = new \Illuminate\Support\HtmlString('<span class="app-num">'.e(MoneyFormat::rate($r->rate)).'</span><span class="app-unit">'.e($book).'</span>');
                $hint = new \Illuminate\Support\HtmlString(e(__('Latest rate · :date', ['date' => substr((string) $r->rate_date, 0, 10)]))
                    .($inverse ? ' · <span class="app-num">1 '.e($book).' = '.e(MoneyFormat::rate($inverse)).' '.e($r->currency).'</span>' : ''));
            @endphp
            <div class="col-sm-6 col-lg-3">
                <x-kpi :label="$r->currency.' · '.($currencies[$r->currency] ?? $r->currency)"
                       :value="$value"
                       icon="currency-exchange" tone="gold"
                       :hint="$hint"
                       :href="route('fx-rates.index', ['currency' => $r->currency])" />
            </div>
        @endforeach
    </div>
@endif

<x-section :title="__('Rates')" icon="list-ul" flush class="app-section--wrap">
    <x-slot:actions>
        <form method="GET" class="app-headfilter" role="search" aria-label="{{ __('Filter FX rates') }}">
            <label class="visually-hidden" for="fx-currency">{{ __('Currency') }}</label>
            <select name="currency" id="fx-currency" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">{{ __('All currencies') }}</option>
                @foreach($currencies as $code => $name)
                    <option value="{{ $code }}" @selected($currency === $code)>{{ $code }} — {{ $name }}</option>
                @endforeach
            </select>
            <noscript><button class="btn btn-sm btn-light">{{ __('Filter') }}</button></noscript>
        </form>
    </x-slot:actions>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Currency') }}</th><th scope="col" class="text-end">{{ __('Rate to :book', ['book' => $book]) }}</th>
                <th scope="col">{{ __('Entered by') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse($rates as $rate)
                <tr>
                    <td><span class="app-num">{{ $rate->rate_date->toDateString() }}</span></td>
                    <td><span class="app-ltr fw-semibold">{{ $rate->currency }}</span> <span class="small text-muted">{{ $currencies[$rate->currency] ?? '' }}</span></td>
                    <td class="text-end"><span class="app-num app-fig fw-semibold">{{ MoneyFormat::rate($rate->rate) }}</span></td>
                    <td>{{ $rate->creator?->name ?? '—' }}</td>
                    <td class="text-end">
                        @can('payments.edit')
                            <a href="{{ route('fx-rates.edit', $rate) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}" aria-label="{{ __('Edit rate') }}: {{ $rate->currency }} {{ $rate->rate_date->toDateString() }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><x-empty icon="currency-exchange" :title="__('No rates yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($rates->hasPages())<div class="p-3 border-top">{{ $rates->links() }}</div>@endif
</x-section>
@endsection
