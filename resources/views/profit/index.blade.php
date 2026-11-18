@extends('layouts.app')

@section('title', __('Profit'))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fig = fn ($v, bool $signed = false) => Decimal::isZero($v)
        ? new HtmlString('<span class="app-fig app-fig--none">—</span>')
        : new HtmlString('<span class="app-num app-fig'.($signed && Decimal::cmp($v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(Decimal::format($v)).'</span>');
    $pct = fn (?string $m) => $m === null ? new HtmlString('<span class="app-fig app-fig--none">—</span>') : new HtmlString('<span class="app-num'.(str_starts_with($m, '-') ? ' app-fig--neg' : '').'">'.e($m).'%</span>');
    $costs = fn ($t) => Decimal::sum([Decimal::sub($t['goods_cost'], $t['debited']), $t['container_cost'], $t['other_cost']]);
@endphp

@section('content')
<x-page-header :title="__('Profit')" :eyebrow="__('Finance')" :subtitle="__('What each sales order earns: sales less the factory\'s price, its share of freight and its other costs.')">
    <x-slot:actions>
        <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}" class="btn btn-light"><i class="bi bi-download" aria-hidden="true"></i>{{ __('Download CSV') }}</a>
    </x-slot:actions>
</x-page-header>
@include('profit._nav', ['active' => 'orders'])

<form method="GET" class="app-filterbar" aria-label="{{ __('Filter') }}">
    <div class="app-filterbar__field" style="flex-basis: 16rem">
        <label class="form-label" for="client">{{ __('Client') }}</label>
        <select id="client" name="client" class="form-select form-select-sm">
            <option value="">{{ __('All clients') }}</option>
            @foreach($clients as $c)<option value="{{ $c->id }}" @selected($clientId === $c->id)>{{ $c->name }} ({{ $c->code }})</option>@endforeach
        </select>
    </div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="from">{{ __('Confirmed from') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
</form>

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('Sales (:book)', ['book' => $book])" :value="$fig(Decimal::add(Decimal::sub($totals['sales'], $totals['credited']), $totals['fees']))" icon="receipt" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Costs (:book)', ['book' => $book])" :value="$fig($costs($totals))" icon="box-seam" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Profit (:book)', ['book' => $book])" :value="$fig($totals['profit'], true)" icon="graph-up-arrow" :tone="Decimal::cmp($totals['profit'], '0') < 0 ? 'late' : 'gold'" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Margin')" :value="$pct($totals['margin'])" icon="percent" /></div>
</div>

@include('profit._split-note')

<x-section :title="__('Sales orders')" icon="receipt" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 app-ledger">
            <thead><tr>
                <th scope="col">{{ __('PI') }}</th>
                <th scope="col">{{ __('Client') }}</th>
                <th scope="col" class="text-end">{{ __('Sales') }}</th>
                <th scope="col" class="text-end">{{ __('Goods cost') }}</th>
                <th scope="col" class="text-end">{{ __('Freight') }}</th>
                <th scope="col" class="text-end">{{ __('Other costs') }}</th>
                <th scope="col" class="text-end">{{ __('Profit') }}</th>
                <th scope="col" class="text-end">{{ __('Margin') }}</th>
            </tr></thead>
            <tbody>
            @forelse($rows as $r)
                <tr>
                    <td class="text-nowrap">
                        <a href="{{ route('profit.pi', $r['pi']) }}" class="app-ltr fw-semibold">{{ $r['pi']->reference() }}</a>
                        @if($r['estimated'])<div><x-status tone="due" icon="hourglass-split">{{ __('Estimated') }}</x-status></div>@endif
                        @if($r['cost_missing'])<div><x-status tone="late" icon="exclamation-triangle">{{ __('A line has no cost') }}</x-status></div>@endif
                    </td>
                    <td><bdi>{{ $r['pi']->client?->name }}</bdi></td>
                    @if($r['error'])
                        <td colspan="6"><x-status tone="late" icon="exclamation-triangle">{{ $r['error'] }}</x-status></td>
                    @else
                        <td class="app-figcell">{{ $fig(Decimal::add(Decimal::sub($r['sales'], $r['credited']), $r['fees'])) }}</td>
                        <td class="app-figcell">{{ $fig(Decimal::sub($r['goods_cost'], $r['debited'])) }}</td>
                        <td class="app-figcell">{{ $fig($r['container_cost']) }}</td>
                        <td class="app-figcell">{{ $fig($r['other_cost']) }}</td>
                        <td class="app-figcell fw-semibold">{{ $fig($r['profit'], true) }}</td>
                        <td class="app-figcell">{{ $pct($r['margin']) }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="8" class="p-0"><x-empty icon="receipt" :title="__('No sales order in these dates.')" :text="__('Issued and confirmed PIs show here, by the date they were confirmed (or issued).')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
