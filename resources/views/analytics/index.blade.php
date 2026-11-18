@extends('layouts.app')

@section('title', __('Analysis'))

@php use App\Services\Accounting\Decimal; @endphp

@section('content')
<x-page-header :title="__('Analysis')" :eyebrow="__('Overview')" :subtitle="__('Sales by month, client, product and account manager; purchases by factory; how quotations turn into orders. In :book.', ['book' => $book])" />

<form method="GET" class="app-filterbar app-filterbar--fit" aria-label="{{ __('Filter') }}">
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
</form>

@if(count($missingRates))
    <p class="app-note mb-3"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ __('Left out for want of an exchange rate: :list. Enter the rates on the FX rates screen.', ['list' => collect($missingRates)->map(fn ($n, $c) => $c.' ('.$n.')')->join(', ')]) }}</span></p>
@endif

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('Sales (:book)', ['book' => $book])" :value="Decimal::format($total)" icon="receipt" tone="gold" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Orders confirmed')" :value="$orders" icon="check2-circle" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Average order (:book)', ['book' => $book])" :value="Decimal::format($average)" icon="calculator" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Quotations won')" :value="$funnel['win_rate'] !== null ? $funnel['win_rate'].'%' : '—'" icon="trophy" :hint="__(':won of :decided answered', ['won' => $funnel['accepted'], 'decided' => $funnel['accepted'] + $funnel['declined'] + $funnel['expired']])" /></div>
</div>

<x-section :title="__('Sales by month')" icon="bar-chart-line" flush class="mb-4" :subtitle="__('Confirmed sales orders, by the month they were confirmed.')">
    @include('analytics._columns', ['months' => $byMonth])
</x-section>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <x-section :title="__('Top clients')" icon="people" flush class="h-100">
            @include('analytics._bars', ['rows' => $byClient, 'link' => fn ($r) => Gate::allows('clients.view') ? route('clients.show', $r['id']) : null])
        </x-section>
    </div>
    <div class="col-lg-6">
        <x-section :title="__('Top products')" icon="box-seam" flush class="h-100">
            @include('analytics._bars', ['rows' => $byProduct])
        </x-section>
    </div>
    <div class="col-lg-6">
        <x-section :title="__('By account manager')" icon="person-badge" flush class="h-100">
            @include('analytics._bars', ['rows' => $byPerson])
        </x-section>
    </div>
    <div class="col-lg-6">
        <x-section :title="__('Quotations')" icon="chat-square-quote" flush class="h-100" :subtitle="__('Dated in the range.')">
            <div class="app-funnel">
                @foreach(['total' => __('Written'), 'sent' => __('Sent'), 'waiting' => __('Waiting for an answer'), 'accepted' => __('Accepted'), 'declined' => __('Declined'), 'expired' => __('Expired'), 'converted' => __('Became a PI')] as $key => $label)
                    <div class="app-funnel__step"><div class="app-funnel__n">{{ $funnel[$key] }}</div><div class="app-funnel__label">{{ $label }}</div></div>
                @endforeach
            </div>
        </x-section>
    </div>
</div>

@if($seesPurchases)
    <div class="row g-3">
        <div class="col-lg-6">
            <x-section :title="__('Purchases by factory')" icon="shop" flush class="h-100" :subtitle="__('Purchase orders confirmed in the range: :amount :book.', ['amount' => Decimal::format($purchaseTotal), 'book' => $book])">
                @include('analytics._bars', ['rows' => $byFactory])
            </x-section>
        </div>
        <div class="col-lg-6">
            <x-section :title="__('Purchases by month')" icon="bar-chart" flush class="h-100">
                @include('analytics._columns', ['months' => $purchaseByMonth, 'dense' => true])
            </x-section>
        </div>
    </div>
@endif
@endsection
