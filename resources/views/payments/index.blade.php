@extends('layouts.app')

@section('title', __('Payments'))

@php use App\Models\Payment; use App\Services\Finance\MoneyFormat; use Illuminate\Support\Facades\Route; @endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Payments') }}</h1>
    @can('payments.edit')
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('payments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('Record a payment') }}</a>
            @if(! \App\Support\Access\Visibility::ownClientsOnly(auth()->user()))
                <a href="{{ route('payments.transfer.create') }}" class="btn btn-light"><i class="bi bi-arrow-left-right me-1"></i>{{ __('Transfer between accounts') }}</a>
            @endif
        </div>
    @endcan
</div>

@include('payments._nav', ['active' => 'payments'])

<form method="GET" class="app-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small">{{ __('Kind') }}</label>
            <select name="kind" class="form-select form-select-sm">
                <option value="">{{ __('All kinds') }}</option>
                @foreach($kinds as $k)
                    <option value="{{ $k }}" @selected($filters['kind'] === $k)>{{ Payment::kindLabel($k) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">{{ __('Money account') }}</label>
            <select name="account" class="form-select form-select-sm">
                <option value="">{{ __('All accounts') }}</option>
                @foreach($accounts as $a)
                    <option value="{{ $a->id }}" @selected($filters['account'] === $a->id)>{{ $a->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">{{ __('Party') }}</label>
            <select name="party" class="form-select form-select-sm">
                <option value="">{{ __('All parties') }}</option>
                @foreach($parties as $p)
                    <option value="{{ $p->id }}" @selected($filters['party'] === $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">{{ __('From') }}</label>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small">{{ __('To') }}</label>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="awaiting" value="1" id="f-awaiting" @checked($filters['awaiting']) @disabled(! $approvalRequired)>
                <label class="form-check-label small" for="f-awaiting">{{ __('Awaiting approval') }}</label>
            </div>
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-primary">{{ __('Filter') }}</button>
                <a href="{{ route('payments.index') }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>
            </div>
        </div>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="app-card app-stat">
        <div class="app-stat__label">{{ __('Money in') }} ({{ $book }})</div>
        <div class="app-stat__value"><span class="app-num">{{ MoneyFormat::amount($totals->total_in) }}</span></div>
    </div></div>
    <div class="col-md-4"><div class="app-card app-stat">
        <div class="app-stat__label">{{ __('Money out') }} ({{ $book }})</div>
        <div class="app-stat__value"><span class="app-num">{{ MoneyFormat::amount($totals->total_out) }}</span></div>
    </div></div>
    <div class="col-md-4"><div class="app-card app-stat">
        <div class="app-stat__label">{{ __('Net') }} ({{ $book }}) · {{ trans_choice('{0} no payments|{1} one payment|[2,*] :count payments', $totals->count, ['count' => $totals->count]) }}</div>
        <div class="app-stat__value"><span class="app-num">{{ MoneyFormat::amount($totals->net) }}</span></div>
    </div></div>
</div>
<p class="text-muted small">{{ __('Totals are in the book currency, at each payment\'s own rate, over every row the filters select (including payments awaiting approval).') }}</p>

<div class="app-card">
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Party') }}</th>
                <th>{{ __('Account') }}</th><th class="text-end">{{ __('Amount') }}</th><th class="text-end">{{ __('Book amount') }}</th>
                <th>{{ __('For') }}</th><th>{{ __('Status') }}</th>
            </tr></thead>
            <tbody>
            @forelse($payments as $payment)
                <tr>
                    <td><a href="{{ route('payments.show', $payment) }}" class="app-ltr fw-semibold">{{ $payment->number }}</a></td>
                    <td class="app-num">{{ $payment->paid_on->toDateString() }}</td>
                    <td>{{ Payment::kindLabel($payment->kind) }}</td>
                    <td>{{ $payment->party?->name ?? '—' }}</td>
                    <td>{{ $payment->moneyAccount->name }}</td>
                    <td class="text-end"><span class="app-num">{{ MoneyFormat::amount($payment->signed_amount) }}</span> <small class="text-muted">{{ $payment->currency }}</small></td>
                    <td class="text-end"><span class="app-num">{{ MoneyFormat::amount($payment->signed_book_amount) }}</span></td>
                    <td class="small">
                        @if($payment->proformaInvoice)
                            @if(Route::has('proforma-invoices.show'))<a href="{{ route('proforma-invoices.show', $payment->proformaInvoice) }}" class="app-ltr">{{ $payment->proformaInvoice->reference() }}</a>@else<span class="app-ltr">{{ $payment->proformaInvoice->reference() }}</span>@endif
                        @endif
                        @if($payment->supplierOrder)
                            @if(Route::has('supplier-orders.show'))<a href="{{ route('supplier-orders.show', $payment->supplierOrder) }}" class="app-ltr">{{ $payment->supplierOrder->number }}</a>@else<span class="app-ltr">{{ $payment->supplierOrder->number }}</span>@endif
                        @endif
                        @if($payment->container)
                            @if(Route::has('containers.show'))<a href="{{ route('containers.show', $payment->container) }}" class="app-ltr">{{ $payment->container->container_no ?: '#'.$payment->container->id }}</a>@else<span class="app-ltr">{{ $payment->container->container_no ?: '#'.$payment->container->id }}</span>@endif
                        @endif
                        @if(! $payment->proformaInvoice && ! $payment->supplierOrder && ! $payment->container)—@endif
                    </td>
                    <td>
                        @include('payments._status', ['status' => $queries->status($payment, $approvalRequired)])
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="app-empty"><i class="bi bi-cash-coin"></i>{{ __('No payments found.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($payments->hasPages())<div class="p-3">{{ $payments->links() }}</div>@endif
</div>
@endsection
