@extends('layouts.app')

@section('title', __('Ageing'))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fig = fn ($v, string $class = '') => Decimal::isZero($v)
        ? new HtmlString('<span class="app-fig app-fig--none">—</span>')
        : new HtmlString('<span class="app-num app-fig '.$class.'">'.e(Decimal::format($v)).'</span>');
    $last = count($labels) - 1;
    $kind = $side === 'receivable' ? 'client' : 'supplier';
@endphp

@section('content')
@include('finance-reports._header', ['title' => __('Ageing'), 'subtitle' => __('Who owes what, and for how long: receivables and payables by age, per currency.')])

<nav class="app-chips" aria-label="{{ __('Ageing') }}">
    @foreach(['receivable' => [__('Owed to MASDAR'), 'box-arrow-in-down'], 'payable' => [__('Owed by MASDAR'), 'box-arrow-up']] as $key => [$label, $icon])
        <a class="app-chip {{ $side === $key ? 'is-active' : '' }}" href="{{ route('finance-reports.ageing', ['side' => $key, 'as_at' => $asAt]) }}" @if($side === $key) aria-current="page" @endif><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</a>
    @endforeach
</nav>

<form method="GET" class="app-filterbar app-filterbar--fit" aria-label="{{ __('As at') }}">
    <input type="hidden" name="side" value="{{ $side }}">
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="as_at">{{ __('As at') }}</label><input id="as_at" type="date" name="as_at" value="{{ $asAt }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
</form>

@if(count($report['totals']))
    <div class="row g-3 mb-4 app-kpis">
        @foreach($report['totals'] as $currency => $t)
            @php $overdue = Decimal::sum(array_slice($t['buckets'], 1)); @endphp
            <div class="col-6 col-lg-3">
                <x-kpi :label="__('Open in :currency', ['currency' => $currency])" :value="$fig($t['open'])" icon="hourglass-split"
                       :hint="Decimal::isPositive($overdue) ? __(':amount older than :days days', ['amount' => Decimal::format($overdue), 'days' => $report['buckets'][0]]) : null"
                       :tone="Decimal::isPositive($t['buckets'][$last]) ? 'late' : null" />
            </div>
        @endforeach
    </div>
@endif

<x-section :title="$side === 'receivable' ? __('Clients') : __('Suppliers and shipping companies')" icon="people" flush
           :subtitle="__('From posted entries: payments settle the oldest invoices first. Amounts in each party\'s currency.')">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 app-ledger">
            <thead><tr>
                <th scope="col">{{ $side === 'receivable' ? __('Client') : __('Party') }}</th>
                <th scope="col" class="text-end">{{ __('Open') }}</th>
                @foreach($labels as $i => $label)<th scope="col" class="text-end text-nowrap">{{ $label }}</th>@endforeach
                <th scope="col" class="text-end">{{ __('Advance') }}</th>
                <th scope="col" class="text-end text-nowrap">{{ __('Oldest open') }}</th>
            </tr></thead>
            @forelse(collect($report['rows'])->groupBy('currency') as $currency => $rows)
                <tbody>
                    <tr class="app-groupband">
                        <th scope="rowgroup"><span class="app-ltr">{{ $currency }}</span> · {{ trans_choice('{1} one party|[2,*] :count parties', $rows->count(), ['count' => $rows->count()]) }}</th>
                        <td class="app-figcell">{{ $fig($report['totals'][$currency]['open']) }}</td>
                        @foreach($report['totals'][$currency]['buckets'] as $amount)<td class="app-figcell">{{ $fig($amount) }}</td>@endforeach
                        <td class="app-figcell">{{ $fig($report['totals'][$currency]['advance']) }}</td>
                        <td></td>
                    </tr>
                    @foreach($rows as $r)
                        @php $p = $r['party']; $stmtKind = $p->is_client && $side === 'receivable' ? 'client' : ($p->is_shipping && ! $p->is_supplier ? 'shipping' : $kind); @endphp
                        <tr>
                            <td><a href="{{ route('finance-reports.party-statement', ['kind' => $stmtKind, 'party_id' => $p->id, 'to' => $asAt]) }}" class="fw-semibold"><bdi>{{ $p->name }}</bdi></a> <span class="small text-muted app-ltr">{{ $p->code }}</span></td>
                            <td class="app-figcell">{{ $fig($r['open']) }}</td>
                            @foreach($r['buckets'] as $i => $amount)<td class="app-figcell">{{ $fig($amount, $i === $last ? 'app-fig--neg' : '') }}</td>@endforeach
                            <td class="app-figcell">{{ $fig($r['advance']) }}</td>
                            <td class="text-end text-nowrap">@if($r['oldest'])<span class="app-num">{{ trans_choice('{0} today|{1} one day|[2,*] :count days', max(0, $r['oldest']['days']), ['count' => max(0, $r['oldest']['days'])]) }}</span>@else<span class="app-fig app-fig--none">—</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="{{ count($labels) + 4 }}" class="p-0"><x-empty icon="check-circle" :title="__('Nothing is open.')" :text="__('Only posted entries count: post invoices, bills and payments on the Accounting screen to see them here.')" /></td></tr></tbody>
            @endforelse
        </table>
    </div>
    @if($report['hidden'] > 0)
        <p class="app-note mx-3 my-3"><i class="bi bi-lock" aria-hidden="true"></i><span>{{ trans_choice('{1} One party is not shown: you may not see it.|[2,*] :count parties are not shown: you may not see them.', $report['hidden'], ['count' => $report['hidden']]) }}</span></p>
    @endif
</x-section>
@endsection
