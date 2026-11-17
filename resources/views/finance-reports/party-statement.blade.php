@extends('layouts.app')

@section('title', __('Party statement'))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fig = fn ($v, bool $blankZero = false) => $blankZero && Decimal::isZero($v)
        ? ''
        : new HtmlString('<span class="app-num app-fig">'.e(Decimal::format($v)).'</span>');
    // A balance as an amount and its side: Dr (the party owes MASDAR) or Cr (MASDAR owes the party).
    $side = fn ($v) => Decimal::format(Decimal::abs($v)).' '.(Decimal::isZero($v) ? '' : (str_starts_with($v, '-') ? __('Cr') : __('Dr')));
    $sideFig = fn ($v) => new HtmlString('<span class="app-num app-fig">'.e($side($v)).'</span>');
@endphp

@section('content')
@include('finance-reports._header', ['title' => __('Party statement'), 'csv' => (bool) $report, 'subtitle' => __('A client\'s, supplier\'s or shipping company\'s postings, with opening and running balance.')])

<nav class="app-chips" aria-label="{{ __('Party') }}">
    @foreach(['client' => [__('Clients'), 'person-badge'], 'supplier' => [__('Suppliers'), 'building'], 'shipping' => [__('Shipping companies'), 'truck']] as $key => [$label, $icon])
        <a class="app-chip {{ $kind === $key ? 'is-active' : '' }}" href="{{ route('finance-reports.party-statement', ['kind' => $key, 'from' => $from, 'to' => $to]) }}" @if($kind === $key) aria-current="page" @endif><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</a>
    @endforeach
</nav>

<form method="GET" class="app-filterbar" aria-label="{{ __('Party statement') }}">
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="app-filterbar__field" style="flex-basis: 20rem">
        <label class="form-label" for="party_id">{{ __('Party') }}</label>
        <select id="party_id" name="party_id" class="form-select form-select-sm" required>
            <option value="">—</option>
            @foreach($parties as $p)
                <option value="{{ $p->id }}" @selected($party?->id === $p->id)>{{ $p->name }} ({{ $p->code }})</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
</form>

@if($report)
    <div class="row g-3 mb-4 app-kpis">
        <div class="col-6 col-lg-3"><x-kpi :label="__('Opening balance')" :value="$sideFig($report['opening'])" icon="box-arrow-in-right" /></div>
        <div class="col-6 col-lg-3"><x-kpi :label="__('Debits')" :value="$fig($report['debit'])" icon="plus-circle" /></div>
        <div class="col-6 col-lg-3"><x-kpi :label="__('Credits')" :value="$fig($report['credit'])" icon="dash-circle" /></div>
        <div class="col-6 col-lg-3"><x-kpi :label="__('Closing balance')" :value="$sideFig($report['closing'])" icon="flag" tone="gold" /></div>
    </div>

    <div class="app-card app-stmt-card">
        @include('finance-reports._stmt-head', ['title' => $party->name.' · '.$party->code, 'from' => $from, 'to' => $to, 'book' => $book])
        <p class="app-note justify-content-center text-center mx-auto mt-3 mb-0 px-3">{{ __('Every posted line carrying this party, in :book. Dr: the party owes MASDAR (or MASDAR has paid ahead); Cr: MASDAR owes the party (or holds its money).', ['book' => $book]) }}</p>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 app-ledger">
                <thead><tr>
                    <th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Entry') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col">{{ __('Account') }}</th>
                    <th scope="col" class="text-end">{{ __('Amount in currency') }}</th><th scope="col" class="text-end">{{ __('Debit') }}</th><th scope="col" class="text-end">{{ __('Credit') }}</th><th scope="col" class="text-end">{{ __('Balance') }}</th>
                </tr></thead>
                <tbody>
                    <tr class="app-ledger__bf"><td colspan="7">{{ __('Opening balance') }}</td><td class="app-figcell">{{ $sideFig($report['opening']) }}</td></tr>
                    @forelse($report['rows'] as $r)
                        @php $accountName = match (app()->getLocale()) { 'ar' => $r['account_name_ar'], 'zh_CN' => $r['account_name_zh'], default => null } ?: $r['account_name']; @endphp
                        <tr>
                            <td class="app-ledger__date"><span class="app-num">{{ substr($r['entry_date'], 0, 10) }}</span></td>
                            <td class="text-nowrap">@can('accounting.view')<a href="{{ route('accounting.journal.show', $r['journal_entry_id']) }}" class="app-ltr">{{ $r['number'] }}</a>@else<span class="app-ltr">{{ $r['number'] }}</span>@endcan</td>
                            <td>{{ $r['description'] }}@if($r['memo'])<span class="app-ledger__memo">{{ $r['memo'] }}</span>@endif</td>
                            <td><span class="app-ltr text-muted small">{{ $r['account_code'] }}</span> {{ $accountName }}</td>
                            <td class="app-figcell"><span class="app-num app-fig">{{ Decimal::format($r['amount_currency']) }}</span><span class="app-unit">{{ $r['currency'] }}</span></td>
                            <td class="app-figcell">{{ $fig($r['debit'], true) }}</td>
                            <td class="app-figcell">{{ $fig($r['credit'], true) }}</td>
                            <td class="app-figcell app-ledger__running">{{ $sideFig($r['running']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-0"><x-empty icon="person-lines-fill" :title="__('Nothing posted in this range.')" /></td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr><th scope="row" colspan="5">{{ __('Closing balance') }}</th><td class="app-figcell">{{ $fig($report['debit']) }}</td><td class="app-figcell">{{ $fig($report['credit']) }}</td><td class="app-figcell">{{ $sideFig($report['closing']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
@else
    <div class="app-card"><x-empty icon="person-lines-fill" :title="$parties->isEmpty() ? __('There is no party of this kind you may see.') : __('Choose a party.')" /></div>
@endif
@endsection
