@extends('layouts.app')

@section('title', __('Profit and loss'))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fig = fn ($v) => new HtmlString('<span class="app-num app-fig'.(Decimal::cmp((string) $v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(Decimal::format($v)).'</span>');
    $loss = str_starts_with($report['net'], '-');
@endphp

@section('content')
@include('finance-reports._header', ['title' => __('Profit and loss'), 'subtitle' => __('Income and expenses for a period, grouped by heading, and the net result.')])

<form method="GET" class="app-filterbar" aria-label="{{ __('Period') }}">
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__check"><div class="form-check"><input class="form-check-input" type="checkbox" name="all" value="1" id="all" @checked($all)><label class="form-check-label small" for="all">{{ __('Show accounts without postings') }}</label></div></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
</form>

<div class="row g-3 mb-4 app-kpis">
    <div class="col-sm-4"><x-kpi :label="__('Income').' ('.$book.')'" :value="$fig($report['income']['total'])" icon="graph-up-arrow" tone="done" /></div>
    <div class="col-sm-4"><x-kpi :label="__('Expenses').' ('.$book.')'" :value="$fig($report['expense']['total'])" icon="receipt-cutoff" /></div>
    <div class="col-sm-4"><x-kpi :label="($loss ? __('Net loss') : __('Net profit')).' ('.$book.')'" :value="$fig($report['net'])" :icon="$loss ? 'graph-down-arrow' : 'trophy'" :tone="$loss ? 'late' : 'gold'" /></div>
</div>

<div class="app-card app-stmt-card">
    @include('finance-reports._stmt-head', ['title' => __('Profit and loss'), 'from' => $from, 'to' => $to, 'book' => $book])
    <div class="table-responsive">
        <table class="table table-hover app-stmt mb-0">
            <thead><tr>
                <th scope="col" class="app-stmt__code">{{ __('Code') }}</th><th scope="col">{{ __('Account') }}</th><th scope="col" class="app-stmt__amt">{{ __('Amount (:book)', ['book' => $book]) }}</th>
            </tr></thead>
            <tbody>
                @include('finance-reports._section', ['title' => __('Income'), 'section' => $report['income'], 'ledgerFrom' => $from, 'ledgerTo' => $to])
                <tr class="app-stmt__spacer"><td colspan="3"></td></tr>
                @include('finance-reports._section', ['title' => __('Expenses'), 'section' => $report['expense'], 'ledgerFrom' => $from, 'ledgerTo' => $to])
            </tbody>
            <tfoot>
                <tr class="app-stmt__grand">
                    <td class="app-stmt__code"></td>
                    <th scope="row">{{ $loss ? __('Net loss') : __('Net profit') }}</th>
                    <td class="app-stmt__amt">{{ $fig($report['net']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
