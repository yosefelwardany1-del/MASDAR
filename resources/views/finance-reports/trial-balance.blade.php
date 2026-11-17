@extends('layouts.app')

@section('title', __('Trial balance'))

@section('content')
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); $t = $report['totals']; @endphp
@include('finance-reports._header', ['title' => __('Trial balance')])

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control"></div>
    <div class="col-6 col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="all" value="1" id="all" @checked($all)><label class="form-check-label" for="all">{{ __('Show accounts without postings') }}</label></div></div>
    <div class="col-6 col-md-3"><button class="btn btn-primary w-100">{{ __('Show') }}</button></div>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr><th rowspan="2">{{ __('Code') }}</th><th rowspan="2">{{ __('Account') }}</th>
                    <th colspan="2" class="text-center">{{ __('Opening') }}</th><th colspan="2" class="text-center">{{ __('Movements') }}</th><th colspan="2" class="text-center">{{ __('Closing') }}</th></tr>
                <tr>@foreach(range(1, 3) as $_)<th class="text-end">{{ __('Debit') }}</th><th class="text-end">{{ __('Credit') }}</th>@endforeach</tr>
            </thead>
            <tbody>
            @forelse($report['rows'] as $row)
                <tr>
                    <td class="app-num">{{ $row['account']->code }}</td>
                    <td>@can('finance_reports.view')<a href="{{ route('finance-reports.account-ledger', ['account_id' => $row['account']->id, 'from' => $from, 'to' => $to]) }}">{{ $row['account']->displayName() }}</a>@endcan</td>
                    @foreach(['opening_dr', 'opening_cr', 'debit', 'credit', 'closing_dr', 'closing_cr'] as $key)
                        <td class="app-num text-end">{{ \App\Services\Accounting\Decimal::isZero($row[$key]) ? '' : $fmt($row[$key]) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="8" class="app-empty">{{ __('Nothing posted in this range.') }}</td></tr>
            @endforelse
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td></td><td>{{ __('Total (:book)', ['book' => $book]) }}</td>
                    @foreach(['opening_dr', 'opening_cr', 'debit', 'credit', 'closing_dr', 'closing_cr'] as $key)
                        <td class="app-num text-end">{{ $fmt($t[$key]) }}</td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@if(\App\Services\Accounting\Decimal::cmp($t['debit'], $t['credit']) !== 0 || \App\Services\Accounting\Decimal::cmp($t['closing_dr'], $t['closing_cr']) !== 0)
    <div class="alert alert-danger mt-3">{{ __('The totals do not agree. Tell the system administrator.') }}</div>
@endif
@endsection
