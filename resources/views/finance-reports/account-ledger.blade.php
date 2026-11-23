@extends('layouts.app')

@section('title', __('Account ledger'))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fig = fn ($v, bool $blankZero = false) => $blankZero && Decimal::isZero($v)
        ? ''
        : new HtmlString('<span class="app-num app-fig'.(Decimal::cmp((string) $v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(Decimal::format($v)).'</span>');
@endphp

@section('content')
@include('finance-reports._header', ['title' => __('Account ledger'), 'csv' => (bool) $report, 'subtitle' => __('One account\'s postings for a range, with a running balance.')])

<form method="GET" class="app-filterbar" aria-label="{{ __('Account ledger') }}">
    <div class="app-filterbar__field" style="flex-basis: 20rem">
        <label class="form-label" for="account_id">{{ __('Account') }}</label>
        <select id="account_id" name="account_id" class="form-select form-select-sm" required>
            <option value="">{{ __('— account —') }}</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" @selected($account?->id === $a->id)>{{ $a->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
</form>

@if($report)
    <div class="row g-3 mb-4 app-kpis">
        <div class="col-6 col-lg-3"><x-kpi :label="__('Opening balance')" :value="$fig($report['opening'])" icon="box-arrow-in-right" /></div>
        <div class="col-6 col-lg-3"><x-kpi :label="__('Debits')" :value="$fig($report['debit'])" icon="plus-circle" /></div>
        <div class="col-6 col-lg-3"><x-kpi :label="__('Credits')" :value="$fig($report['credit'])" icon="dash-circle" /></div>
        <div class="col-6 col-lg-3"><x-kpi :label="__('Closing balance')" :value="$fig($report['closing'])" icon="flag" tone="gold" /></div>
    </div>

    <div class="app-card app-stmt-card">
        @include('finance-reports._stmt-head', ['title' => $account->label(), 'from' => $from, 'to' => $to, 'book' => $book])
        <p class="app-note justify-content-center text-center mx-auto mt-3 mb-0 px-3">{{ match ($account->type) {
            'asset' => __('Balances in :book, positive when normal for an asset account.', ['book' => $book]),
            'liability' => __('Balances in :book, positive when normal for a liability account.', ['book' => $book]),
            'equity' => __('Balances in :book, positive when normal for an equity account.', ['book' => $book]),
            'income' => __('Balances in :book, positive when normal for an income account.', ['book' => $book]),
            default => __('Balances in :book, positive when normal for an expense account.', ['book' => $book]),
        } }}</p>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 app-ledger">
                <thead><tr>
                    <th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Entry') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col">{{ __('Party') }}</th>
                    <th scope="col" class="text-end">{{ __('Amount in currency') }}</th><th scope="col" class="text-end">{{ __('Debit') }}</th><th scope="col" class="text-end">{{ __('Credit') }}</th><th scope="col" class="text-end">{{ __('Balance') }}</th>
                </tr></thead>
                <tbody>
                    <tr class="app-ledger__bf"><td colspan="7">{{ __('Opening balance') }}</td><td class="app-figcell">{{ $fig($report['opening']) }}</td></tr>
                    @forelse($report['rows'] as $r)
                        <tr>
                            <td class="app-ledger__date"><span class="app-num">{{ substr($r['entry_date'], 0, 10) }}</span></td>
                            <td class="text-nowrap"><a href="{{ route('accounting.journal.show', $r['journal_entry_id']) }}" class="app-ltr">{{ $r['number'] }}</a></td>
                            <td>{{ $r['description'] }}@if($r['memo'])<span class="app-ledger__memo">{{ $r['memo'] }}</span>@endif</td>
                            <td>{{ $r['party_name'] ?? '—' }}</td>
                            <td class="app-figcell"><span class="app-num app-fig">{{ \App\Services\Accounting\Decimal::format($r['amount_currency']) }}</span><span class="app-unit">{{ $r['currency'] }}</span></td>
                            <td class="app-figcell">{{ $fig($r['debit'], true) }}</td>
                            <td class="app-figcell">{{ $fig($r['credit'], true) }}</td>
                            <td class="app-figcell app-ledger__running">{{ $fig($r['running']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-0"><x-empty icon="journal-text" :title="__('Nothing posted in this range.')" /></td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr><th scope="row" colspan="5">{{ __('Closing balance') }}</th><td class="app-figcell">{{ $fig($report['debit']) }}</td><td class="app-figcell">{{ $fig($report['credit']) }}</td><td class="app-figcell">{{ $fig($report['closing']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
@else
    <div class="app-card"><x-empty icon="journal-text" :title="__('Choose an account.')" :text="__('Pick an account and a range above to see its postings and running balance.')" /></div>
@endif
@endsection
