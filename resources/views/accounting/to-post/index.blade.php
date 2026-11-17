@extends('layouts.app')

@section('title', __('To post'))

@section('content')
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); @endphp
<h1 class="h4 mb-3">{{ __('Accounting') }}</h1>
@include('accounting._nav', ['active' => 'to-post'])

<p class="text-muted small">{{ __('Documents and payments waiting for their journal entry. Each posts once, by its posting rule; if a rule, a money account or an FX rate is missing, the message says what to set.') }}</p>

{{-- Payments --}}
<div class="app-card mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-bottom">
        <h2 class="h6 mb-0">{{ __('Payments') }} <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $payments->count() }}</span></h2>
        @if($payments->isNotEmpty())
            <form method="POST" action="{{ route('accounting.to-post.payments') }}">@csrf <button class="btn btn-sm btn-primary">{{ __('Post all') }}</button></form>
        @endif
    </div>
    @if($awaitingApproval > 0)
        <div class="px-3 pt-2 small text-muted">{{ trans_choice('{1} One payment out is awaiting a manager\'s approval and is posted once approved.|[2,*] :count payments out are awaiting a manager\'s approval and are posted once approved.', $awaitingApproval, ['count' => $awaitingApproval]) }}</div>
    @endif
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Money account') }}</th><th>{{ __('Party') }}</th>
                <th class="text-end">{{ __('Amount') }}</th><th class="text-end">{{ __('In :book', ['book' => $book]) }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($payments as $payment)
                <tr>
                    <td class="app-num text-nowrap">
                        @if(Route::has('payments.show'))<a href="{{ route('payments.show', $payment) }}">{{ $payment->number }}</a>@else{{ $payment->number }}@endif
                        @if($payment->reverses)<div class="small text-muted">{{ __('reverses :original', ['original' => $payment->reverses->number]) }}</div>@endif
                    </td>
                    <td class="app-num text-nowrap">{{ $payment->paid_on->toDateString() }}</td>
                    <td>{{ \App\Services\Accounting\PostingService::eventLabel($payment->kind) }}</td>
                    <td>{{ $payment->moneyAccount?->name }}</td>
                    <td>{{ $payment->party?->name ?? '—' }}</td>
                    <td class="app-num text-end text-nowrap">{{ $fmt($payment->amount) }} {{ $payment->currency }}</td>
                    <td class="app-num text-end">{{ $fmt($payment->book_amount) }}</td>
                    <td class="text-end"><form method="POST" action="{{ route('accounting.to-post.payment', $payment) }}">@csrf <button class="btn btn-sm btn-light">{{ __('Post') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="8" class="app-empty">{{ __('Every effective payment is posted.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Client invoices --}}
<div class="app-card mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-bottom">
        <h2 class="h6 mb-0">{{ __('PIs to invoice') }} <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $invoices->count() }}</span></h2>
        @if($invoices->isNotEmpty())
            <form method="POST" action="{{ route('accounting.to-post.invoices') }}">@csrf <button class="btn btn-sm btn-primary">{{ __('Post all') }}</button></form>
        @endif
    </div>
    <div class="px-3 pt-2 small text-muted">{{ __('Confirmed PIs, invoiced on their confirmation date unless you pick another.') }}</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>{{ __('PI') }}</th><th>{{ __('Client') }}</th><th>{{ __('Confirmed') }}</th><th class="text-end">{{ __('Amount') }}</th><th>{{ __('Invoice date') }}</th><th></th></tr></thead>
            <tbody>
            @forelse($invoices as $pi)
                <tr>
                    <td class="app-num text-nowrap">@if(Route::has('proforma-invoices.show'))<a href="{{ route('proforma-invoices.show', $pi) }}">{{ $pi->reference() }}</a>@else{{ $pi->reference() }}@endif</td>
                    <td>{{ $pi->client?->name }}</td>
                    <td class="app-num">{{ $pi->confirmed_at?->timezone(config('app.timezone'))->toDateString() ?? '—' }}</td>
                    <td class="app-num text-end text-nowrap">{{ $fmt($pi->lines_sum_amount) }} {{ $pi->currency }}</td>
                    <td><input type="date" name="posting_date" form="pi-{{ $pi->id }}" class="form-control form-control-sm" style="max-inline-size: 170px"></td>
                    <td class="text-end"><form id="pi-{{ $pi->id }}" method="POST" action="{{ route('accounting.to-post.invoice', $pi) }}">@csrf <button class="btn btn-sm btn-light">{{ __('Post') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="6" class="app-empty">{{ __('No confirmed PI is waiting to be invoiced.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Supplier bills --}}
<div class="app-card mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-bottom">
        <h2 class="h6 mb-0">{{ __('Supplier orders to bill') }} <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $bills->count() }}</span></h2>
        @if($bills->isNotEmpty())
            <form method="POST" action="{{ route('accounting.to-post.bills') }}">@csrf <button class="btn btn-sm btn-primary">{{ __('Post all') }}</button></form>
        @endif
    </div>
    <div class="px-3 pt-2 small text-muted">{{ __('Orders whose every line is fully received, billed on the last receipt date unless you pick another.') }}</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>{{ __('Order') }}</th><th>{{ __('Supplier') }}</th><th>{{ __('Received') }}</th><th class="text-end">{{ __('Amount') }}</th><th>{{ __('Bill date') }}</th><th></th></tr></thead>
            <tbody>
            @forelse($bills as $order)
                <tr>
                    <td class="app-num text-nowrap">@if(Route::has('supplier-orders.show'))<a href="{{ route('supplier-orders.show', $order) }}">{{ $order->number }}</a>@else{{ $order->number }}@endif</td>
                    <td>{{ $order->supplier?->name }}</td>
                    <td class="app-num">{{ $order->received_on ? substr($order->received_on, 0, 10) : '—' }}</td>
                    <td class="app-num text-end text-nowrap">{{ $fmt($order->lines_sum_amount) }} {{ $order->currency }}</td>
                    <td><input type="date" name="posting_date" form="bill-{{ $order->id }}" class="form-control form-control-sm" style="max-inline-size: 170px"></td>
                    <td class="text-end"><form id="bill-{{ $order->id }}" method="POST" action="{{ route('accounting.to-post.bill', $order) }}">@csrf <button class="btn btn-sm btn-light">{{ __('Post') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="6" class="app-empty">{{ __('No fully received supplier order is waiting to be billed.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Shipping company bill --}}
<form method="POST" action="{{ route('accounting.to-post.shipping-bill') }}" class="app-card p-3">
    @csrf
    <h2 class="h6 mb-3">{{ __('A shipping company\'s bill') }}</h2>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="shipping_company_id">{{ __('Shipping company') }} <span class="text-danger">*</span></label>
            <select id="shipping_company_id" name="shipping_company_id" class="form-select @error('shipping_company_id') is-invalid @enderror" required>
                <option value="">—</option>
                @foreach($shippingCompanies as $company)
                    <option value="{{ $company->id }}" @selected((string) old('shipping_company_id') === (string) $company->id)>{{ $company->name }}</option>
                @endforeach
            </select>
            @error('shipping_company_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="container_id">{{ __('Container') }}</label>
            <select id="container_id" name="container_id" class="form-select @error('container_id') is-invalid @enderror">
                <option value="">{{ __('— none —') }}</option>
                @foreach($containers as $container)
                    <option value="{{ $container->id }}" @selected((string) old('container_id') === (string) $container->id)>{{ $container->container_no ?: '#'.$container->id }}</option>
                @endforeach
            </select>
            @error('container_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="bill_date">{{ __('Bill date') }} <span class="text-danger">*</span></label>
            <input id="bill_date" name="bill_date" type="date" class="form-control @error('bill_date') is-invalid @enderror" value="{{ old('bill_date', now()->toDateString()) }}" required>
            @error('bill_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="amount">{{ __('Amount') }} <span class="text-danger">*</span></label>
            <input id="amount" name="amount" dir="ltr" inputmode="decimal" class="form-control app-num @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required>
            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-2">
            <label class="form-label" for="currency">{{ __('Currency') }}</label>
            <select id="currency" name="currency" class="form-select">
                @foreach($currencies as $code)
                    <option value="{{ $code }}" @selected(old('currency', $book) === $code)>{{ $code }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="rate">{{ __('Rate') }}</label>
            <input id="rate" name="rate" dir="ltr" inputmode="decimal" class="form-control app-num @error('rate') is-invalid @enderror" value="{{ old('rate') }}" placeholder="{{ __('FX rate of the day') }}">
            @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-5">
            <label class="form-label" for="bill_description">{{ __('Description') }}</label>
            <input id="bill_description" name="description" class="form-control" value="{{ old('description') }}" maxlength="500">
        </div>
    </div>
    <div class="mt-3"><button class="btn btn-primary">{{ __('Post the bill') }}</button></div>
</form>
@endsection
