@extends('layouts.app')

@section('title', __('Profit and loss'))

@section('content')
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); @endphp
@include('finance-reports._header', ['title' => __('Profit and loss')])

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control"></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control"></div>
    <div class="col-6 col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="all" value="1" id="all" @checked($all)><label class="form-check-label" for="all">{{ __('Show accounts without postings') }}</label></div></div>
    <div class="col-6 col-md-3"><button class="btn btn-primary w-100">{{ __('Show') }}</button></div>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Account') }}</th><th class="text-end">{{ __('Amount (:book)', ['book' => $book]) }}</th></tr></thead>
            <tbody>
                @include('finance-reports._section', ['title' => __('Income'), 'section' => $report['income']])
                @include('finance-reports._section', ['title' => __('Expenses'), 'section' => $report['expense']])
            </tbody>
            <tfoot>
                <tr class="fw-bold fs-6 {{ str_starts_with($report['net'], '-') ? 'text-danger' : '' }}">
                    <td></td><td>{{ str_starts_with($report['net'], '-') ? __('Net loss') : __('Net profit') }}</td><td class="app-num text-end">{{ $fmt($report['net']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
