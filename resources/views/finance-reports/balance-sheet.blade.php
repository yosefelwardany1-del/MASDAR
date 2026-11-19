@extends('layouts.app')

@section('title', __('Balance sheet'))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fig = fn ($v) => new HtmlString('<span class="app-num app-fig'.(Decimal::cmp((string) $v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(Decimal::format($v)).'</span>');
@endphp

@section('content')
@include('finance-reports._header', ['title' => __('Balance sheet'), 'subtitle' => __('Assets, liabilities and equity as at a date, with the year\'s result.')])

<form method="GET" class="app-filterbar" aria-label="{{ __('As at') }}">
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="as_at">{{ __('As at') }}</label><input id="as_at" type="date" name="as_at" value="{{ $asAt }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__check"><div class="form-check"><input class="form-check-input" type="checkbox" name="all" value="1" id="all" @checked($all)><label class="form-check-label small" for="all">{{ __('Show accounts without postings') }}</label></div></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
    {{-- B32: the year's start is a setting, not a field of this report. --}}
    <div class="form-text w-100 m-0">{{ __('This financial year started on :date: the result is split between this year and earlier years from then (Lists & options).', ['date' => $yearStart]) }}
        @can('configuration.view')<a href="{{ route('configuration.index', ['tab' => 'options']) }}#profit">{{ __('Change the financial year') }}</a>@endcan
    </div>
</form>

<div class="row g-3 mb-4 app-kpis">
    <div class="col-sm-4"><x-kpi :label="__('Total assets').' ('.$book.')'" :value="$fig($report['assets']['total'])" icon="bank" /></div>
    <div class="col-sm-4"><x-kpi :label="__('Liabilities').' ('.$book.')'" :value="$fig($report['liabilities']['total'])" icon="credit-card-2-back" /></div>
    <div class="col-sm-4"><x-kpi :label="__('Equity').' ('.$book.')'" :value="$fig($report['equity_total'])" icon="pie-chart" tone="gold" /></div>
</div>

<div class="app-card app-stmt-card">
    @include('finance-reports._stmt-head', ['title' => __('Balance sheet'), 'asAt' => $asAt, 'book' => $book])
    <div class="table-responsive">
        <table class="table table-hover app-stmt mb-0">
            <thead><tr>
                <th scope="col" class="app-stmt__code">{{ __('Code') }}</th><th scope="col">{{ __('Account') }}</th><th scope="col" class="app-stmt__amt">{{ __('Amount (:book)', ['book' => $book]) }}</th>
            </tr></thead>
            <tbody>
                @include('finance-reports._section', ['title' => __('Assets'), 'section' => $report['assets'], 'hideTotal' => true, 'ledgerTo' => $asAt, 'ledgerFrom' => $yearStart])
                <tr class="app-stmt__grand"><td class="app-stmt__code"></td><th scope="row">{{ __('Total assets') }}</th><td class="app-stmt__amt">{{ $fig($report['assets']['total']) }}</td></tr>
                <tr class="app-stmt__spacer"><td colspan="3"></td></tr>

                @include('finance-reports._section', ['title' => __('Liabilities'), 'section' => $report['liabilities'], 'ledgerTo' => $asAt, 'ledgerFrom' => $yearStart])
                @include('finance-reports._section', ['title' => __('Equity'), 'section' => $report['equity'], 'hideTotal' => true, 'ledgerTo' => $asAt, 'ledgerFrom' => $yearStart])
                <tr class="app-stmt__row"><td class="app-stmt__code"></td><td class="app-stmt__name">{{ __('Result of earlier years not yet carried to equity') }}</td><td class="app-stmt__amt">{{ $fig($report['earlier_years']) }}</td></tr>
                <tr class="app-stmt__row"><td class="app-stmt__code"></td><td class="app-stmt__name">{{ __('Current-year result') }}</td><td class="app-stmt__amt">{{ $fig($report['current_year']) }}</td></tr>
                <tr class="app-stmt__total"><td class="app-stmt__code"></td><th scope="row">{{ __('Total :section', ['section' => __('Equity')]) }}</th><td class="app-stmt__amt">{{ $fig($report['equity_total']) }}</td></tr>
            </tbody>
            <tfoot>
                <tr class="app-stmt__grand"><td class="app-stmt__code"></td><th scope="row">{{ __('Liabilities and equity') }}</th><td class="app-stmt__amt">{{ $fig($report['liabilities_and_equity']) }}</td></tr>
            </tfoot>
        </table>
    </div>
</div>
<div class="app-verdict">
    @if($report['balanced'])
        <x-status tone="done" icon="check2-circle">{{ __('The balance sheet balances.') }}</x-status>
        <span>{{ __('Total assets equal liabilities and equity.') }}</span>
    @else
        <div class="alert alert-danger w-100 mb-0" role="alert"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>{{ __('The balance sheet does not balance. Tell the system administrator.') }}</div>
    @endif
</div>
@endsection
