@extends('layouts.app')

@section('title', __('Trial balance'))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $t = $report['totals'];
    $fig = fn ($v, bool $blankZero = true) => $blankZero && Decimal::isZero($v)
        ? ''
        : new HtmlString('<span class="app-num app-fig'.(Decimal::cmp((string) $v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(Decimal::format($v)).'</span>');
    $keys = ['opening_dr', 'opening_cr', 'debit', 'credit', 'closing_dr', 'closing_cr'];
    $agree = Decimal::cmp($t['debit'], $t['credit']) === 0 && Decimal::cmp($t['closing_dr'], $t['closing_cr']) === 0;
@endphp

@section('content')
@include('finance-reports._header', ['title' => __('Trial balance'), 'subtitle' => __('Opening, debits, credits and closing balance of every account for a date range.')])

<form method="GET" class="app-filterbar" aria-label="{{ __('Period') }}">
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__check"><div class="form-check"><input class="form-check-input" type="checkbox" name="all" value="1" id="all" @checked($all)><label class="form-check-label small" for="all">{{ __('Show accounts without postings') }}</label></div></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
</form>

<div class="app-card app-stmt-card">
    @include('finance-reports._stmt-head', ['title' => __('Trial balance'), 'from' => $from, 'to' => $to, 'book' => $book])
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 app-tb app-ledger">
            <thead>
                <tr>
                    <th rowspan="2" scope="col" class="app-stmt__code">{{ __('Code') }}</th><th rowspan="2" scope="col">{{ __('Account') }}</th>
                    <th colspan="2" scope="colgroup" class="app-tb__group">{{ __('Opening') }}</th>
                    <th colspan="2" scope="colgroup" class="app-tb__group">{{ __('Movements') }}</th>
                    <th colspan="2" scope="colgroup" class="app-tb__group">{{ __('Closing') }}</th>
                </tr>
                <tr>@foreach(range(1, 3) as $n)<th scope="col" class="text-end app-tb__start">{{ __('Debit') }}</th><th scope="col" class="text-end">{{ __('Credit') }}</th>@endforeach</tr>
            </thead>
            <tbody>
            @forelse($report['rows'] as $row)
                <tr>
                    <td class="app-stmt__code"><span class="app-num">{{ $row['account']->code }}</span></td>
                    <td>@can('finance_reports.view')<a href="{{ route('finance-reports.account-ledger', ['account_id' => $row['account']->id, 'from' => $from, 'to' => $to]) }}">{{ $row['account']->displayName() }}</a>@endcan</td>
                    @foreach($keys as $i => $key)
                        <td class="app-figcell {{ $i % 2 === 0 ? 'app-tb__start' : '' }}">{{ $fig($row[$key]) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="8" class="p-0"><x-empty icon="list-columns" :title="__('Nothing posted in this range.')" /></td></tr>
            @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td></td><th scope="row">{{ __('Total (:book)', ['book' => $book]) }}</th>
                    @foreach($keys as $i => $key)
                        <td class="app-figcell {{ $i % 2 === 0 ? 'app-tb__start' : '' }}">{{ $fig($t[$key], false) }}</td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<div class="app-verdict">
    @if($agree)
        <x-status tone="done" icon="check2-circle">{{ __('Balanced') }}</x-status>
        <span>{{ __('Debits equal credits, and the closing balances agree.') }}</span>
    @else
        <div class="alert alert-danger w-100 mb-0" role="alert"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>{{ __('The totals do not agree. Tell the system administrator.') }}</div>
    @endif
</div>
@endsection
