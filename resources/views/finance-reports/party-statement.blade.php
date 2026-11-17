@extends('layouts.app')

@section('title', __('Party statement'))

@section('content')
@php
    $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v);
    $side = fn ($v) => \App\Services\Accounting\Decimal::format(\App\Services\Accounting\Decimal::abs($v)).' '.(\App\Services\Accounting\Decimal::isZero($v) ? '' : (str_starts_with($v, '-') ? __('Cr') : __('Dr')));
@endphp
@include('finance-reports._header', ['title' => __('Party statement'), 'csv' => (bool) $report])

<ul class="nav nav-pills mb-3">
    @foreach(['client' => __('Clients'), 'supplier' => __('Suppliers'), 'shipping' => __('Shipping companies')] as $key => $label)
        <li class="nav-item"><a class="nav-link {{ $kind === $key ? 'active' : '' }}" href="{{ route('finance-reports.party-statement', ['kind' => $key, 'from' => $from, 'to' => $to]) }}">{{ $label }}</a></li>
    @endforeach
</ul>

<form method="GET" class="row g-2 mb-3 align-items-end">
    <input type="hidden" name="kind" value="{{ $kind }}">
    <div class="col-12 col-md-5">
        <label class="form-label small" for="party_id">{{ __('Party') }}</label>
        <select id="party_id" name="party_id" class="form-select" required>
            <option value="">—</option>
            @foreach($parties as $p)
                <option value="{{ $p->id }}" @selected($party?->id === $p->id)>{{ $p->name }} ({{ $p->code }})</option>
            @endforeach
        </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control"></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control"></div>
    <div class="col-12 col-md-3"><button class="btn btn-primary w-100">{{ __('Show') }}</button></div>
</form>

@if($report)
    <p class="text-muted small">{{ __('Every posted line carrying this party, in :book. Dr: the party owes MASDAR (or MASDAR has paid ahead); Cr: MASDAR owes the party (or holds its money).', ['book' => $book]) }}</p>
    <div class="app-card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th>{{ __('Description') }}</th><th>{{ __('Account') }}</th>
                    <th class="text-end">{{ __('Amount in currency') }}</th><th class="text-end">{{ __('Debit') }}</th><th class="text-end">{{ __('Credit') }}</th><th class="text-end">{{ __('Balance') }}</th>
                </tr></thead>
                <tbody>
                    <tr class="table-light"><td colspan="7">{{ __('Opening balance') }}</td><td class="app-num text-end text-nowrap">{{ $side($report['opening']) }}</td></tr>
                    @forelse($report['rows'] as $r)
                        @php $accountName = match (app()->getLocale()) { 'ar' => $r['account_name_ar'], 'zh_CN' => $r['account_name_zh'], default => null } ?: $r['account_name']; @endphp
                        <tr>
                            <td class="app-num text-nowrap">{{ substr($r['entry_date'], 0, 10) }}</td>
                            <td class="app-num text-nowrap">@can('accounting.view')<a href="{{ route('accounting.journal.show', $r['journal_entry_id']) }}">{{ $r['number'] }}</a>@else{{ $r['number'] }}@endcan</td>
                            <td>{{ $r['description'] }}@if($r['memo'])<div class="small text-muted">{{ $r['memo'] }}</div>@endif</td>
                            <td><span class="app-num">{{ $r['account_code'] }}</span> {{ $accountName }}</td>
                            <td class="app-num text-end text-nowrap">{{ $fmt($r['amount_currency']) }} {{ $r['currency'] }}</td>
                            <td class="app-num text-end">{{ \App\Services\Accounting\Decimal::isZero($r['debit']) ? '' : $fmt($r['debit']) }}</td>
                            <td class="app-num text-end">{{ \App\Services\Accounting\Decimal::isZero($r['credit']) ? '' : $fmt($r['credit']) }}</td>
                            <td class="app-num text-end text-nowrap">{{ $side($r['running']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="app-empty">{{ __('Nothing posted in this range.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="fw-bold"><td colspan="5">{{ __('Closing balance') }}</td><td class="app-num text-end">{{ $fmt($report['debit']) }}</td><td class="app-num text-end">{{ $fmt($report['credit']) }}</td><td class="app-num text-end text-nowrap">{{ $side($report['closing']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
@else
    <div class="app-card p-4 text-muted">{{ $parties->isEmpty() ? __('There is no party of this kind you may see.') : __('Choose a party.') }}</div>
@endif
@endsection
