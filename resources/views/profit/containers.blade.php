@extends('layouts.app')

@section('title', __('Profit by container'))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fig = fn ($v, bool $signed = false) => Decimal::isZero($v)
        ? new HtmlString('<span class="app-fig app-fig--none">—</span>')
        : new HtmlString('<span class="app-num app-fig'.($signed && Decimal::cmp($v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(Decimal::format($v)).'</span>');
    $pct = fn (?string $m) => $m === null ? new HtmlString('<span class="app-fig app-fig--none">—</span>') : new HtmlString('<span class="app-num'.(str_starts_with($m, '-') ? ' app-fig--neg' : '').'">'.e($m).'%</span>');
@endphp

@section('content')
<x-page-header :title="__('Profit')" :eyebrow="__('Finance')" :subtitle="__('What each container earns: the goods loaded at their sales and factory prices, less the container\'s own costs.')">
    <x-slot:actions>
        <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}" class="btn btn-light"><i class="bi bi-download" aria-hidden="true"></i>{{ __('Download CSV') }}</a>
    </x-slot:actions>
</x-page-header>
@include('profit._nav', ['active' => 'containers'])

<form method="GET" class="app-filterbar app-filterbar--fit" aria-label="{{ __('Filter') }}">
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="from">{{ __('Departing from') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
</form>

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('Sales (:book)', ['book' => $book])" :value="$fig($totals['sales'])" icon="receipt" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Goods cost (:book)', ['book' => $book])" :value="$fig($totals['goods_cost'])" icon="box-seam" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Container costs (:book)', ['book' => $book])" :value="$fig($totals['container_cost'])" icon="truck" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Profit (:book)', ['book' => $book])" :value="$fig($totals['profit'], true)" icon="graph-up-arrow" :tone="Decimal::cmp($totals['profit'], '0') < 0 ? 'late' : 'gold'" :hint="$totals['margin'] !== null ? __('Margin :pct%', ['pct' => $totals['margin']]) : null" /></div>
</div>

@include('profit._split-note')

@forelse($rows as $r)
    @php $c = $r['container']; @endphp
    <x-section :title="($c->container_no ?: '#'.$c->id).' · '.($c->shipment?->number ?? '')" icon="box" flush class="mb-3"
               :subtitle="$c->shipment?->etd ? __('ETD :date', ['date' => $c->shipment->etd->toDateString()]) : null">
        @if(Route::has('containers.show'))
            <x-slot:actions><a href="{{ route('containers.show', $c) }}" class="btn btn-sm btn-light">{{ __('Open the container') }}</a></x-slot:actions>
        @endif
        @if($r['error'])
            <div class="p-3"><x-status tone="late" icon="exclamation-triangle">{{ $r['error'] }}</x-status></div>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 app-ledger">
                    <thead><tr>
                        <th scope="col">{{ __('Client') }}</th><th scope="col">{{ __('PI') }}</th>
                        <th scope="col" class="text-end">{{ __('Sales') }}</th><th scope="col" class="text-end">{{ __('Goods cost') }}</th>
                        <th scope="col" class="text-end">{{ __('Share of container costs') }}</th><th scope="col" class="text-end">{{ __('Profit') }}</th><th scope="col" class="text-end">{{ __('Margin') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($r['clients'] as $cl)
                        <tr>
                            <td><bdi>{{ $cl['client'] }}</bdi></td>
                            <td><span class="app-ltr">{{ implode(', ', $cl['pi_numbers']) }}</span></td>
                            <td class="app-figcell">{{ $fig($cl['sales']) }}</td>
                            <td class="app-figcell">{{ $fig($cl['goods_cost']) }}</td>
                            <td class="app-figcell">{{ $fig($cl['container_cost']) }}</td>
                            <td class="app-figcell fw-semibold">{{ $fig($cl['profit'], true) }}</td>
                            <td class="app-figcell">{{ $pct($cl['margin']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    @unless($limited)
                        <tfoot><tr class="app-ledger__total">
                            <th scope="row" colspan="2">{{ __('Container') }}</th>
                            <td class="app-figcell">{{ $fig($r['sales']) }}</td>
                            <td class="app-figcell">{{ $fig($r['goods_cost']) }}</td>
                            <td class="app-figcell">{{ $fig($r['container_cost']) }}</td>
                            <td class="app-figcell">{{ $fig($r['profit'], true) }}</td>
                            <td class="app-figcell">{{ $pct($r['margin']) }}</td>
                        </tr></tfoot>
                    @endunless
                </table>
            </div>
            @if(Decimal::isZero($r['container_cost']))
                <p class="app-note mx-3 my-2"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ __('No cost yet: post the shipping company\'s bill for this container, or pay its expenses against it.') }}</span></p>
            @endif
        @endif
    </x-section>
@empty
    <x-empty icon="boxes" :title="__('No loaded container in these dates.')" :text="__('Containers show here once goods are loaded, by the shipment\'s departure date.')" />
@endforelse
@endsection
