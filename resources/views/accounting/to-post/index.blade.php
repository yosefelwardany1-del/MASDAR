@extends('layouts.app')

@section('title', __('To post'))

@section('content')
@php
    $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v);
    $waiting = $payments->count() + $invoices->count() + $bills->count() + $payrolls->count() + $notes->count() + $claims->count();
    $seesPayroll = auth()->user()->can('payroll.view');
@endphp
<x-page-header :title="__('To post')" :eyebrow="__('Accounting')"
               :subtitle="__('Documents and payments waiting for their journal entry. Each posts once, by its posting rule; if a rule, a money account or an FX rate is missing, the message says what to set.')" />
@include('accounting._nav', ['active' => 'to-post'])

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('Payments')" :value="$payments->count()" icon="cash-coin" :tone="$payments->count() ? 'due' : 'done'" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('PIs to invoice')" :value="$invoices->count()" icon="receipt" :tone="$invoices->count() ? 'due' : 'done'" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Supplier orders to bill')" :value="$bills->count()" icon="box-seam" :tone="$bills->count() ? 'due' : 'done'" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Awaiting approval')" :value="$awaitingApproval" icon="hourglass-split" :hint="__('Posted once approved')" /></div>
</div>

{{-- Payments --}}
<x-section :title="__('Payments')" icon="cash-coin" flush class="mb-4 app-section--wrap">
    @if($payments->isNotEmpty())
        <x-slot:actions>
            <form method="POST" action="{{ route('accounting.to-post.payments') }}">@csrf <button class="btn btn-sm btn-primary"><i class="bi bi-check2-all" aria-hidden="true"></i>{{ __('Post all') }}</button></form>
        </x-slot:actions>
    @endif
    @if($awaitingApproval > 0)
        <p class="app-note mx-3 mt-3 mb-0"><i class="bi bi-hourglass-split" aria-hidden="true"></i><span>{{ trans_choice('{1} One payment out is awaiting a manager\'s approval and is posted once approved.|[2,*] :count payments out are awaiting a manager\'s approval and are posted once approved.', $awaitingApproval, ['count' => $awaitingApproval]) }}</span></p>
    @endif
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Money account') }}</th><th scope="col">{{ __('Party') }}</th>
                <th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col" class="text-end">{{ __('In :book', ['book' => $book]) }}</th><th scope="col"><span class="visually-hidden">{{ __('Post') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse($payments as $payment)
                <tr>
                    <td class="text-nowrap">
                        @if(Route::has('payments.show'))<a href="{{ route('payments.show', $payment) }}" class="app-ltr fw-semibold">{{ $payment->number }}</a>@else<span class="app-ltr">{{ $payment->number }}</span>@endif
                        @if($payment->reverses)<div class="small text-muted">{{ __('reverses :original', ['original' => $payment->reverses->number]) }}</div>@endif
                    </td>
                    <td class="text-nowrap"><span class="app-num">{{ $payment->paid_on->toDateString() }}</span></td>
                    <td class="small">{{ \App\Services\Accounting\PostingService::eventLabel($payment->kind) }}</td>
                    <td>{{ $payment->moneyAccount?->name }}</td>
                    <td>{{ $payment->party?->name ?? '—' }}</td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($payment->amount) }}</span><span class="app-unit">{{ $payment->currency }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($payment->book_amount) }}</span></td>
                    <td class="text-end"><form method="POST" action="{{ route('accounting.to-post.payment', $payment) }}">@csrf <button class="btn btn-sm btn-light">{{ __('Post') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-0"><div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('Every effective payment is posted.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>

{{-- Client invoices --}}
<x-section :title="__('PIs to invoice')" icon="receipt" flush class="mb-4 app-section--wrap">
    @if($invoices->isNotEmpty())
        <x-slot:actions>
            <form method="POST" action="{{ route('accounting.to-post.invoices') }}">@csrf <button class="btn btn-sm btn-primary"><i class="bi bi-check2-all" aria-hidden="true"></i>{{ __('Post all') }}</button></form>
        </x-slot:actions>
    @endif
    <p class="app-note mx-3 mt-3 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('Confirmed PIs, invoiced on their confirmation date unless you pick another.') }}</span></p>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('PI') }}</th><th scope="col">{{ __('Client') }}</th><th scope="col">{{ __('Confirmed') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Invoice date') }}</th><th scope="col"><span class="visually-hidden">{{ __('Post') }}</span></th></tr></thead>
            <tbody>
            @forelse($invoices as $pi)
                <tr>
                    <td class="text-nowrap">@if(Route::has('proforma-invoices.show'))<a href="{{ route('proforma-invoices.show', $pi) }}" class="app-ltr fw-semibold">{{ $pi->reference() }}</a>@else<span class="app-ltr">{{ $pi->reference() }}</span>@endif</td>
                    <td>{{ $pi->client?->name }}</td>
                    <td><span class="app-num">{{ $pi->confirmed_at?->timezone(config('app.timezone'))->toDateString() ?? '—' }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($pi->lines_sum_amount) }}</span><span class="app-unit">{{ $pi->currency }}</span></td>
                    <td><input type="date" name="posting_date" form="pi-{{ $pi->id }}" class="form-control form-control-sm" style="max-inline-size: 170px" aria-label="{{ __('Invoice date') }}: {{ $pi->reference() }}"></td>
                    <td class="text-end"><form id="pi-{{ $pi->id }}" method="POST" action="{{ route('accounting.to-post.invoice', $pi) }}">@csrf <button class="btn btn-sm btn-light">{{ __('Post') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-0"><div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('No confirmed PI is waiting to be invoiced.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>

{{-- Supplier bills --}}
<x-section :title="__('Supplier orders to bill')" icon="box-seam" flush class="mb-4 app-section--wrap">
    @if($bills->isNotEmpty())
        <x-slot:actions>
            <form method="POST" action="{{ route('accounting.to-post.bills') }}">@csrf <button class="btn btn-sm btn-primary"><i class="bi bi-check2-all" aria-hidden="true"></i>{{ __('Post all') }}</button></form>
        </x-slot:actions>
    @endif
    <p class="app-note mx-3 mt-3 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('Orders whose every line is fully received, billed on the last receipt date unless you pick another.') }}</span></p>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Order') }}</th><th scope="col">{{ __('Supplier') }}</th><th scope="col">{{ __('Received') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Bill date') }}</th><th scope="col"><span class="visually-hidden">{{ __('Post') }}</span></th></tr></thead>
            <tbody>
            @forelse($bills as $order)
                <tr>
                    <td class="text-nowrap">@if(Route::has('supplier-orders.show'))<a href="{{ route('supplier-orders.show', $order) }}" class="app-ltr fw-semibold">{{ $order->number }}</a>@else<span class="app-ltr">{{ $order->number }}</span>@endif</td>
                    <td>{{ $order->supplier?->name }}</td>
                    <td><span class="app-num">{{ $order->received_on ? substr($order->received_on, 0, 10) : '—' }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($order->lines_sum_amount) }}</span><span class="app-unit">{{ $order->currency }}</span></td>
                    <td><input type="date" name="posting_date" form="bill-{{ $order->id }}" class="form-control form-control-sm" style="max-inline-size: 170px" aria-label="{{ __('Bill date') }}: {{ $order->number }}"></td>
                    <td class="text-end"><form id="bill-{{ $order->id }}" method="POST" action="{{ route('accounting.to-post.bill', $order) }}">@csrf <button class="btn btn-sm btn-light">{{ __('Post') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-0"><div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('No fully received supplier order is waiting to be billed.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>

{{-- Payroll (spec §5.7): salaries are seen by whoever sees payroll. --}}
@if($payrolls->isNotEmpty() || $seesPayroll)
<x-section :title="__('Payroll to post')" icon="wallet2" flush class="mb-4 app-section--wrap">
    @if($payrolls->isNotEmpty() && $seesPayroll)
        <x-slot:actions>
            <form method="POST" action="{{ route('accounting.to-post.payrolls') }}">@csrf <button class="btn btn-sm btn-primary"><i class="bi bi-check2-all" aria-hidden="true"></i>{{ __('Post all') }}</button></form>
        </x-slot:actions>
    @endif
    <p class="app-note mx-3 mt-3 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('Approved payroll runs, booked on the last day of their month: salaries, employer contributions, tax withheld and net pay owed.') }}</span></p>
    @if($seesPayroll)
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Payroll run') }}</th><th scope="col">{{ __('Month') }}</th><th scope="col" class="text-end">{{ __('Gross') }}</th><th scope="col" class="text-end">{{ __('Net pay') }}</th><th scope="col"><span class="visually-hidden">{{ __('Post') }}</span></th></tr></thead>
            <tbody>
            @forelse($payrolls as $run)
                <tr>
                    <td class="text-nowrap"><a href="{{ route('payroll.show', $run) }}" class="app-ltr fw-semibold">{{ $run->number }}</a></td>
                    <td>{{ $run->periodLabel() }}</td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($run->lines_sum_gross) }}</span><span class="app-unit">{{ $run->currency }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($run->lines_sum_net) }}</span><span class="app-unit">{{ $run->currency }}</span></td>
                    <td class="text-end"><form method="POST" action="{{ route('accounting.to-post.payroll', $run) }}">@csrf <button class="btn btn-sm btn-light">{{ __('Post') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('No approved payroll run is waiting to be posted.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @else
        <div class="app-panel-empty"><i class="bi bi-lock" aria-hidden="true"></i>{{ trans_choice('{1} One payroll run is waiting; posting it needs access to payroll.|[2,*] :count payroll runs are waiting; posting them needs access to payroll.', $payrolls->count(), ['count' => $payrolls->count()]) }}</div>
    @endif
</x-section>
@endif

{{-- Credit and debit notes (spec §5.8). A debit note names a factory: only for those who see suppliers. --}}
@php $seesSuppliers = \App\Support\Access\Visibility::canSeeSupplierPrices(auth()->user()); @endphp
<x-section :title="__('Credit and debit notes to post')" icon="arrow-left-right" flush class="mb-4 app-section--wrap">
    @if($notes->isNotEmpty())
        <x-slot:actions>
            <form method="POST" action="{{ route('accounting.to-post.notes') }}">@csrf <button class="btn btn-sm btn-primary"><i class="bi bi-check2-all" aria-hidden="true"></i>{{ __('Post all') }}</button></form>
        </x-slot:actions>
    @endif
    <p class="app-note mx-3 mt-3 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('Issued notes, posted on their date: a credit note lowers what the client owes, a debit note what MASDAR owes the factory.') }}</span></p>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Note') }}</th><th scope="col">{{ __('Party') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col"><span class="visually-hidden">{{ __('Post') }}</span></th></tr></thead>
            <tbody>
            @forelse($notes as $note)
                <tr>
                    <td class="text-nowrap">
                        @if(Route::has('trade-notes.show') && auth()->user()->can('claims.view'))<a href="{{ route('trade-notes.show', $note) }}" class="app-ltr fw-semibold">{{ $note->number }}</a>@else<span class="app-ltr">{{ $note->number }}</span>@endif
                        <div class="small text-muted">{{ $note->kindLabel() }}</div>
                    </td>
                    <td>{{ $note->isCredit() || $seesSuppliers ? $note->party?->name : __('(hidden)') }}</td>
                    <td><span class="app-num">{{ $note->issued_on?->toDateString() }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($note->lines_sum_amount) }}</span><span class="app-unit">{{ $note->currency }}</span></td>
                    <td class="text-end"><form method="POST" action="{{ route('accounting.to-post.note', $note) }}">@csrf <button class="btn btn-sm btn-light">{{ __('Post') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('No issued note is waiting to be posted.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>

{{-- Staff expense claims (spec §5.8). --}}
<x-section :title="__('Expense claims to post')" icon="receipt-cutoff" flush class="mb-4 app-section--wrap">
    @if($claims->isNotEmpty())
        <x-slot:actions>
            <form method="POST" action="{{ route('accounting.to-post.claims') }}">@csrf <button class="btn btn-sm btn-primary"><i class="bi bi-check2-all" aria-hidden="true"></i>{{ __('Post all') }}</button></form>
        </x-slot:actions>
    @endif
    <p class="app-note mx-3 mt-3 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('Approved claims, booked on the day they were approved: each receipt to its category\'s account, owed to the claimant until paid back.') }}</span></p>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Claim') }}</th><th scope="col">{{ __('Claimant') }}</th><th scope="col">{{ __('Approved') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col"><span class="visually-hidden">{{ __('Post') }}</span></th></tr></thead>
            <tbody>
            @forelse($claims as $claim)
                <tr>
                    <td class="text-nowrap">@can('expense_claims.view')<a href="{{ route('expense-claims.show', $claim) }}" class="app-ltr fw-semibold">{{ $claim->number }}</a>@else<span class="app-ltr">{{ $claim->number }}</span>@endcan</td>
                    <td>{{ $claim->user?->name }}</td>
                    <td><span class="app-num">{{ $claim->latestDecision?->created_at?->timezone(config('app.timezone'))->toDateString() }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($claim->lines_sum_amount) }}</span><span class="app-unit">{{ $claim->currency }}</span></td>
                    <td class="text-end"><form method="POST" action="{{ route('accounting.to-post.claim', $claim) }}">@csrf <button class="btn btn-sm btn-light">{{ __('Post') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('No approved claim is waiting to be posted.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>

{{-- Shipping company bill --}}
<form method="POST" action="{{ route('accounting.to-post.shipping-bill') }}" class="app-form">
    @csrf
    <x-section :title="__('A shipping company\'s bill')" icon="truck" :subtitle="__('Posted at once, with no document behind it.')">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="shipping_company_id">{{ __('Shipping company') }}<span class="app-req" aria-hidden="true">*</span></label>
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
                <label class="form-label" for="bill_date">{{ __('Bill date') }}<span class="app-req" aria-hidden="true">*</span></label>
                <input id="bill_date" name="bill_date" type="date" class="form-control @error('bill_date') is-invalid @enderror" value="{{ old('bill_date', now()->toDateString()) }}" required>
                @error('bill_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="amount">{{ __('Amount') }}<span class="app-req" aria-hidden="true">*</span></label>
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
    </x-section>
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2-circle" aria-hidden="true"></i>{{ __('Post the bill') }}</button>
    </div>
</form>
@endsection
