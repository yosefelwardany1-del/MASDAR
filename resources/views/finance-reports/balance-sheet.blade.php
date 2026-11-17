@extends('layouts.app')

@section('title', __('Balance sheet'))

@section('content')
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); @endphp
@include('finance-reports._header', ['title' => __('Balance sheet')])

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small" for="as_at">{{ __('As at') }}</label><input id="as_at" type="date" name="as_at" value="{{ $asAt }}" class="form-control"></div>
    <div class="col-6 col-md-3">
        <label class="form-label small" for="year_start">{{ __('Financial year starts on') }}</label>
        <input id="year_start" type="date" name="year_start" value="{{ $yearStart }}" class="form-control">
    </div>
    <div class="col-6 col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="all" value="1" id="all" @checked($all)><label class="form-check-label" for="all">{{ __('Show accounts without postings') }}</label></div></div>
    <div class="col-6 col-md-3"><button class="btn btn-primary w-100">{{ __('Show') }}</button></div>
    <div class="col-12 form-text">{{ __('MASDAR\'s financial year end is still open (spec Q-A4): set the start of the year here. It only splits the result between this year and earlier years; the totals are the same.') }}</div>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Account') }}</th><th class="text-end">{{ __('Amount (:book)', ['book' => $book]) }}</th></tr></thead>
            <tbody>
                @include('finance-reports._section', ['title' => __('Assets'), 'section' => $report['assets']])
                @include('finance-reports._section', ['title' => __('Liabilities'), 'section' => $report['liabilities']])
                @include('finance-reports._section', ['title' => __('Equity'), 'section' => $report['equity'], 'hideTotal' => true])
                <tr><td></td><td>{{ __('Result of earlier years not yet carried to equity') }}</td><td class="app-num text-end">{{ $fmt($report['earlier_years']) }}</td></tr>
                <tr><td></td><td>{{ __('Current-year result') }}</td><td class="app-num text-end">{{ $fmt($report['current_year']) }}</td></tr>
                <tr class="fw-bold"><td></td><td>{{ __('Total :section', ['section' => __('Equity')]) }}</td><td class="app-num text-end">{{ $fmt($report['equity_total']) }}</td></tr>
            </tbody>
            <tfoot>
                <tr class="fw-bold"><td></td><td>{{ __('Total assets') }}</td><td class="app-num text-end">{{ $fmt($report['assets']['total']) }}</td></tr>
                <tr class="fw-bold"><td></td><td>{{ __('Liabilities and equity') }}</td><td class="app-num text-end">{{ $fmt($report['liabilities_and_equity']) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>
@if($report['balanced'])
    <div class="alert alert-success mt-3 py-2"><i class="bi bi-check2-circle me-1"></i>{{ __('The balance sheet balances.') }}</div>
@else
    <div class="alert alert-danger mt-3">{{ __('The balance sheet does not balance. Tell the system administrator.') }}</div>
@endif
@endsection
