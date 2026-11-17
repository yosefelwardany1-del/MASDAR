@extends('layouts.app')

@section('title', __('Payments'))

@php
    use App\Models\Payment;
    use App\Services\Accounting\Decimal;
    use App\Services\Finance\MoneyFormat;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\HtmlString;

    // A money figure: tabular, isolated left-to-right, with its minus sign; red when $signal and negative
    // (a total), plain in the list, where money out is normal and the arrow says which way it went.
    $fig = fn ($v, ?string $unit = null, bool $signal = true) => new HtmlString(
        '<span class="app-num app-fig'.($signal && Decimal::cmp((string) $v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(MoneyFormat::amount($v)).'</span>'
        .($unit ? '<span class="app-unit">'.e($unit).'</span>' : '')
    );
    $filtered = collect($filters)->filter()->isNotEmpty();
@endphp

@section('content')
<x-page-header :title="__('Payments')" :subtitle="__('Money in and out of MASDAR\'s accounts. A payment is never edited or deleted: a correction is a reversal.')">
    @can('payments.edit')
        <x-slot:actions>
            <a href="{{ route('payments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Record a payment') }}</a>
            @if(! \App\Support\Access\Visibility::ownClientsOnly(auth()->user()))
                <a href="{{ route('payments.transfer.create') }}" class="btn btn-light"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>{{ __('Transfer between accounts') }}</a>
            @endif
        </x-slot:actions>
    @endcan
</x-page-header>

@include('payments._nav', ['active' => 'payments'])

@if($awaitingCount > 0 && ! $filters['awaiting'])
    <div class="app-callout" role="status">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <span class="app-callout__text">{{ trans_choice('{1} One payment out is waiting for your approval.|[2,*] :count payments out are waiting for your approval.', $awaitingCount, ['count' => $awaitingCount]) }}</span>
        <a href="{{ route('payments.index', ['awaiting' => 1]) }}" class="btn btn-sm btn-warning">{{ __('Show them') }}</a>
    </div>
@endif

<form method="GET" class="app-filterbar" role="search" aria-label="{{ __('Filter payments') }}">
    <div class="app-filterbar__field">
        <label class="form-label" for="f-kind">{{ __('Kind') }}</label>
        <select name="kind" id="f-kind" class="form-select form-select-sm">
            <option value="">{{ __('All kinds') }}</option>
            @foreach($kinds as $k)
                <option value="{{ $k }}" @selected($filters['kind'] === $k)>{{ Payment::kindLabel($k) }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__field app-filterbar__field--wide">
        <label class="form-label" for="f-account">{{ __('Money account') }}</label>
        <select name="account" id="f-account" class="form-select form-select-sm">
            <option value="">{{ __('All accounts') }}</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" @selected($filters['account'] === $a->id)>{{ $a->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__field app-filterbar__field--wide">
        <label class="form-label" for="f-party">{{ __('Party') }}</label>
        <select name="party" id="f-party" class="form-select form-select-sm">
            <option value="">{{ __('All parties') }}</option>
            @foreach($parties as $p)
                <option value="{{ $p->id }}" @selected($filters['party'] === $p->id)>{{ $p->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__field app-filterbar__field--narrow">
        <label class="form-label" for="f-from">{{ __('From') }}</label>
        <input type="date" name="from" id="f-from" value="{{ $filters['from'] }}" class="form-control form-control-sm">
    </div>
    <div class="app-filterbar__field app-filterbar__field--narrow">
        <label class="form-label" for="f-to">{{ __('To') }}</label>
        <input type="date" name="to" id="f-to" value="{{ $filters['to'] }}" class="form-control form-control-sm">
    </div>
    <div class="app-filterbar__check">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="awaiting" value="1" id="f-awaiting" @checked($filters['awaiting']) @disabled(! $approvalRequired)>
            <label class="form-check-label small" for="f-awaiting">{{ __('Awaiting approval') }}</label>
        </div>
    </div>
    <div class="app-filterbar__actions">
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Filter') }}</button>
        @if($filtered)<a href="{{ route('payments.index') }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>@endif
    </div>
</form>

<div class="row g-3 mb-2">
    <div class="col-sm-4">
        <x-kpi :label="__('Money in').' ('.$book.')'" :value="$fig($totals->total_in)" icon="box-arrow-in-down" tone="done" />
    </div>
    <div class="col-sm-4">
        <x-kpi :label="__('Money out').' ('.$book.')'" :value="$fig($totals->total_out)" icon="box-arrow-up" />
    </div>
    <div class="col-sm-4">
        <x-kpi :label="__('Net').' ('.$book.')'" :value="$fig($totals->net)" icon="arrow-left-right" tone="gold"
               :hint="trans_choice('{0} no payments|{1} one payment|[2,*] :count payments', $totals->count, ['count' => $totals->count])" />
    </div>
</div>
<p class="app-note"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('Totals are in the book currency, at each payment\'s own rate, over every row the filters select (including payments awaiting approval).') }}</span></p>

@if($collections->isNotEmpty())
    <x-section :title="__('Collections on open PIs')" icon="receipt" :subtitle="__('Paid against total, in each PI\'s currency')" class="mb-4">
        @if(Route::has('proforma-invoices.index'))
            <x-slot:actions><a href="{{ route('proforma-invoices.index') }}">{{ __('All proforma invoices') }}</a></x-slot:actions>
        @endif
        <ul class="app-collect list-unstyled mb-0">
            @foreach($collections as $pi)
                @php
                    $total = (string) $pi->total_amount;
                    $paid = (string) $pi->paid_amount;
                    [$tone, $statusTone, $statusIcon, $statusText] = match (true) {
                        Decimal::cmp($total, '0') <= 0 => ['primary', 'neutral', 'dash-circle', __('No lines yet')],
                        Decimal::cmp($paid, $total) > 0 => ['late', 'late', 'exclamation-triangle', __('Paid more than the total')],
                        Decimal::cmp($paid, $total) === 0 => ['done', 'done', 'check-circle', __('Paid in full')],
                        Decimal::isPositive($paid) => ['gold', 'due', 'hourglass-split', __('Part paid')],
                        default => ['primary', 'neutral', 'circle', __('Not paid yet')],
                    };
                @endphp
                <li class="app-collect__item">
                    <div class="app-collect__top">
                        <div class="app-collect__who">
                            @if(Route::has('proforma-invoices.show'))<a href="{{ route('proforma-invoices.show', $pi) }}" class="app-ltr">{{ $pi->reference() }}</a>@else<span class="app-ltr fw-semibold">{{ $pi->reference() }}</span>@endif
                            <span class="app-collect__client">· {{ $pi->client?->name }}</span>
                        </div>
                        <x-status :tone="$statusTone" :icon="$statusIcon">{{ $statusText }}</x-status>
                    </div>
                    <x-progress :value="$paid" :max="$total" :label="__('Paid')" :tone="$tone"
                                :caption="MoneyFormat::amount($paid).' / '.MoneyFormat::amount($total).' '.$pi->currency" />
                </li>
            @endforeach
        </ul>
    </x-section>
@endif

<x-section :title="__('Payments')" icon="list-ul" flush
           :subtitle="$payments->total() ? trans_choice('{0} no payments|{1} one payment|[2,*] :count payments', $payments->total(), ['count' => $payments->total()]) : null">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th scope="col">{{ __('Number') }} <span class="fw-normal">· {{ __('Date') }}</span></th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Party') }}</th>
                <th scope="col">{{ __('Account') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col" class="text-end text-nowrap">{{ __('Book amount') }} <span class="app-unit">{{ $book }}</span></th>
                <th scope="col">{{ __('For') }}</th><th scope="col">{{ __('Status') }}</th>
            </tr></thead>
            <tbody>
            @forelse($payments as $payment)
                @php $in = $payment->direction === 'in'; @endphp
                <tr>
                    <td class="text-nowrap">
                        <a href="{{ route('payments.show', $payment) }}" class="app-ltr fw-semibold">{{ $payment->number }}</a>
                        <div class="small text-muted"><span class="visually-hidden">{{ __('Date') }}: </span><span class="app-num">{{ $payment->paid_on->toDateString() }}</span></div>
                    </td>
                    <td>
                        <span class="app-kind">
                            <span class="app-dir {{ $in ? 'app-dir--in' : 'app-dir--out' }}"><i class="bi {{ $in ? 'bi-arrow-down-left' : 'bi-arrow-up-right' }}" aria-hidden="true"></i><span class="visually-hidden">{{ $in ? __('Money in') : __('Money out') }}</span></span>
                            {{ Payment::kindLabel($payment->kind) }}
                        </span>
                    </td>
                    <td>{{ $payment->party?->name ?? '—' }}</td>
                    <td>{{ $payment->moneyAccount->name }}</td>
                    <td class="text-end text-nowrap">{{ $fig($payment->signed_amount, $payment->currency, false) }}</td>
                    <td class="text-end text-nowrap">{{ $fig($payment->signed_book_amount, null, false) }}</td>
                    <td class="small text-nowrap">
                        @if($payment->proformaInvoice)
                            @if(Route::has('proforma-invoices.show'))<a href="{{ route('proforma-invoices.show', $payment->proformaInvoice) }}" class="app-ltr">{{ $payment->proformaInvoice->reference() }}</a>@else<span class="app-ltr">{{ $payment->proformaInvoice->reference() }}</span>@endif
                        @endif
                        @if($payment->supplierOrder)
                            @if(Route::has('supplier-orders.show'))<a href="{{ route('supplier-orders.show', $payment->supplierOrder) }}" class="app-ltr">{{ $payment->supplierOrder->number }}</a>@else<span class="app-ltr">{{ $payment->supplierOrder->number }}</span>@endif
                        @endif
                        @if($payment->container)
                            @if(Route::has('containers.show'))<a href="{{ route('containers.show', $payment->container) }}" class="app-ltr">{{ $payment->container->container_no ?: '#'.$payment->container->id }}</a>@else<span class="app-ltr">{{ $payment->container->container_no ?: '#'.$payment->container->id }}</span>@endif
                        @endif
                        @if(! $payment->proformaInvoice && ! $payment->supplierOrder && ! $payment->container)<span class="text-muted">—</span>@endif
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">@include('payments._pills', ['payment' => $payment])</div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-0">
                    <x-empty icon="cash-coin" :title="__('No payments found.')" :text="$filtered ? __('Nothing matches these filters. Clear them to see every payment.') : __('Recorded receipts, payments and transfers appear here.')" />
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($payments->hasPages())<div class="p-3 border-top">{{ $payments->links() }}</div>@endif
</x-section>
@endsection
