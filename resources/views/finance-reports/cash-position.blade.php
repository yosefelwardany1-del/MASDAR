@extends('layouts.app')

@section('title', __('Cash position'))

@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fig = fn ($v) => new HtmlString('<span class="app-num app-fig'.(Decimal::cmp((string) $v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(Decimal::format($v)).'</span>');
    $currencyNames = \App\Models\Lookup::options('currency');
    // "≈ 700.00 USD" under a currency's total, kept left-to-right as one run.
    $approx = fn (string $currency) => isset($inBook[$currency]['book']) && $currency !== $book
        ? new HtmlString('<span class="app-num">≈ '.e(Decimal::format($inBook[$currency]['book'])).' '.e($book).'</span>')
        : null;
    // Each account's share of the largest balance in its own currency, for the bar in its row.
    $rowsByCurrency = collect($report['rows'])->groupBy(fn ($r) => $r['account']->currency);
    $maxByCurrency = $rowsByCurrency->map(fn ($group) => $group->map(fn ($r) => Decimal::abs($r['native']))
        ->reduce(fn ($carry, $v) => Decimal::cmp($v, $carry) > 0 ? $v : $carry, '0'));
@endphp

@section('content')
@include('finance-reports._header', ['title' => __('Cash position'), 'subtitle' => __('Each money account: its ledger balance and the balance of its payments, per currency.')])

<form method="GET" class="app-filterbar app-filterbar--fit" aria-label="{{ __('As at') }}">
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="as_at">{{ __('As at') }}</label><input id="as_at" type="date" name="as_at" value="{{ $asAt }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
</form>

<div class="row g-3 mb-4 app-kpis">
    @foreach($report['by_currency'] as $currency => $total)
        <div class="col-6 col-lg-3">
            <x-kpi :label="$currency.' · '.($currencyNames[$currency] ?? $currency)" :value="$fig($total)" icon="cash-stack"
                   :hint="$approx($currency)" />
        </div>
    @endforeach
    <div class="col-6 col-lg-3">
        <x-kpi :label="__('Ledger total (:book)', ['book' => $book])" :value="$fig($report['book_total'])" icon="journal-check" tone="gold" />
    </div>
</div>

@if(count($inBook))
    <x-section :title="__('Cash by currency')" icon="bar-chart-line" :subtitle="__('Balances from payments; bars compare them in :book at the rate of :date', ['book' => $book, 'date' => $asAt])" class="mb-4">
        @include('money-accounts._currency-bars', ['inBook' => $inBook, 'book' => $book])
    </x-section>
@endif

<x-section :title="__('By money account')" icon="wallet2" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 app-ledger">
            <thead><tr>
                <th scope="col">{{ __('Money account') }}</th>
                <th scope="col" class="text-end">{{ __('Balance from payments') }}</th>
                <th scope="col">{{ __('Ledger account') }}</th><th scope="col" class="text-end">{{ __('Ledger balance (:book)', ['book' => $book]) }}</th>
            </tr></thead>
            @forelse($rowsByCurrency as $currency => $group)
                <tbody>
                    <tr class="app-groupband">
                        <th scope="rowgroup"><span class="app-ltr">{{ $currency }}</span> · {{ trans_choice('{1} one account|[2,*] :count accounts', $group->count(), ['count' => $group->count()]) }}</th>
                        <td class="app-figcell">{{ $fig($report['by_currency'][$currency] ?? '0') }}<span class="app-unit">{{ $currency }}</span></td>
                        <td colspan="2"></td>
                    </tr>
                    @foreach($group as $r)
                        @php
                            $negative = Decimal::cmp($r['native'], '0') < 0;
                            $max = $maxByCurrency[$currency] ?? '0';
                            $width = Decimal::isPositive($max) ? round((float) Decimal::abs($r['native']) / (float) $max * 100, 1) : 0;
                        @endphp
                        <tr class="{{ $r['account']->active ? '' : 'text-muted' }}">
                            <td>
                                @if(Route::has('money-accounts.show') && auth()->user()->can('payments.view'))<a href="{{ route('money-accounts.show', $r['account']) }}" class="fw-semibold">{{ $r['account']->name }}</a>@else<span class="fw-semibold">{{ $r['account']->name }}</span>@endif
                                @unless($r['account']->active)<x-status tone="skip" class="ms-1">{{ __('Inactive') }}</x-status>@endunless
                            </td>
                            <td class="text-end" style="min-inline-size: 11rem">
                                {{ $fig($r['native']) }}<span class="app-unit">{{ $currency }}</span>
                                @if($group->count() > 1)
                                    <span class="app-minibar {{ $negative ? 'app-minibar--neg' : '' }}" aria-hidden="true"><span style="inline-size: {{ $width }}%"></span></span>
                                @endif
                            </td>
                            <td>@if($r['account']->ledgerAccount){{ $r['account']->ledgerAccount->label() }}@else<x-status tone="due" icon="exclamation-triangle">{{ __('Not set: set it on the Payments screen.') }}</x-status>@endif</td>
                            <td class="app-figcell">@if($r['book'] === null)<span class="app-fig app-fig--none">—</span>@else{{ $fig($r['book']) }}@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="4" class="p-0"><x-empty icon="bank" :title="__('No money accounts yet.')" /></td></tr></tbody>
            @endforelse
        </table>
    </div>
</x-section>
<p class="app-note mt-2"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('The balance from payments is the sum of the account\'s effective payments in its own currency; the ledger balance is its ledger account in :book, from posted entries. A difference means payments wait to be posted, or the ledger account is shared or adjusted by hand.', ['book' => $book]) }}</span></p>
@endsection
