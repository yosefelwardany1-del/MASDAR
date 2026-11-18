@extends('layouts.app')

@section('title', __('Profit of :pi', ['pi' => $pi->reference()]))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fig = fn ($v, bool $signed = false) => new HtmlString('<span class="app-num app-fig'.($signed && Decimal::cmp($v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(Decimal::format($v)).'</span>');
    $r = $row;
@endphp

@section('content')
<x-page-header :title="__('Profit of :pi', ['pi' => $pi->reference()])" :back="route('profit.index')" :back-label="__('Profit')" :subtitle="$pi->client?->name">
    @if(Route::has('proforma-invoices.show'))
        <x-slot:actions><a href="{{ route('proforma-invoices.show', $pi) }}" class="btn btn-light"><i class="bi bi-receipt" aria-hidden="true"></i>{{ __('Open the PI') }}</a></x-slot:actions>
    @endif
</x-page-header>

@if($r['error'])
    <div class="alert alert-warning">{{ $r['error'] }}</div>
@else
    <div class="row g-3 mb-4 app-kpis">
        <div class="col-6 col-lg-3"><x-kpi :label="__('Sales (:book)', ['book' => $book])" :value="$fig(Decimal::sub($r['sales'], $r['credited']))" icon="receipt" /></div>
        <div class="col-6 col-lg-3"><x-kpi :label="__('Profit (:book)', ['book' => $book])" :value="$fig($r['profit'], true)" icon="graph-up-arrow" :tone="Decimal::cmp($r['profit'], '0') < 0 ? 'late' : 'gold'" /></div>
        <div class="col-6 col-lg-3"><x-kpi :label="__('Margin')" :value="$r['margin'] !== null ? $r['margin'].'%' : '—'" icon="percent" /></div>
        <div class="col-6 col-lg-3"><x-kpi :label="__('Containers')" :value="$r['containers']" icon="boxes" /></div>
    </div>

    @include('profit._split-note')

    <x-section :title="__('How it adds up')" icon="calculator" flush class="mb-4">
        <table class="table align-middle mb-0 app-ledger">
            <tbody>
                <tr><th scope="row">{{ __('Sales: the PI\'s lines') }} <span class="small text-muted app-ltr">({{ \App\Services\ProformaInvoices\PiAmounts::money($r['sales_doc'], $pi->currency) }})</span></th><td class="app-figcell">{{ $fig($r['sales']) }}</td></tr>
                @if(Decimal::isPositive($r['credited']))<tr><th scope="row">{{ __('less credit notes to the client') }}</th><td class="app-figcell">{{ $fig(Decimal::neg($r['credited']), true) }}</td></tr>@endif
                @if(Decimal::isPositive($r['fees']))<tr><th scope="row">{{ __('plus service invoices on the PI') }}</th><td class="app-figcell">{{ $fig($r['fees']) }}</td></tr>@endif
                <tr><th scope="row">{{ __('less goods cost: the factories\' prices') }} @if($r['estimated'])<x-status tone="due" icon="hourglass-split" class="ms-1">{{ __('Estimated: some lines are not ordered yet') }}</x-status>@endif</th><td class="app-figcell">{{ $fig(Decimal::neg($r['goods_cost']), true) }}</td></tr>
                @if(Decimal::isPositive($r['debited']))<tr><th scope="row">{{ __('plus debit notes on the factories (this PI\'s part)') }}</th><td class="app-figcell">{{ $fig($r['debited']) }}</td></tr>@endif
                <tr><th scope="row">{{ __('less its share of container costs') }}</th><td class="app-figcell">{{ $fig(Decimal::neg($r['container_cost']), true) }}</td></tr>
                <tr><th scope="row">{{ __('less other costs paid against the PI') }}</th><td class="app-figcell">{{ $fig(Decimal::neg($r['other_cost']), true) }}</td></tr>
            </tbody>
            <tfoot><tr class="app-ledger__total"><th scope="row">{{ __('Profit') }}</th><td class="app-figcell">{{ $fig($r['profit'], true) }}</td></tr></tfoot>
        </table>
        @if($r['cost_missing'])
            <p class="app-note mx-3 my-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ __('A line has no supplier price and is on no purchase order: its cost is not counted.') }}</span></p>
        @endif
    </x-section>

    @if(count($containers))
        <x-section :title="__('Containers')" icon="boxes" flush>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 app-ledger">
                    <thead><tr><th scope="col">{{ __('Container') }}</th><th scope="col" class="text-end">{{ __('Container costs') }}</th><th scope="col">{{ __('Clients inside') }}</th></tr></thead>
                    <tbody>
                    @foreach($containers as $c)
                        <tr>
                            <td>@if(Route::has('containers.show'))<a href="{{ route('containers.show', $c['container']) }}" class="app-ltr">{{ $c['container']->container_no ?: '#'.$c['container']->id }}</a>@else<span class="app-ltr">{{ $c['container']->container_no }}</span>@endif</td>
                            <td class="app-figcell">{{ $fig($c['container_cost']) }}</td>
                            <td>{{ count($c['clients']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-section>
    @endif
@endif
@endsection
