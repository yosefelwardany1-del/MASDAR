@extends('layouts.app')

@php
    use App\Models\SourcingRequest;
    use App\Services\Accounting\Decimal;
    $canEdit = Gate::allows('sourcing.edit');
    $open = $sourcing->isOpen();
    $plain = fn ($x) => $x === null ? '—' : (rtrim(rtrim((string) $x, '0'), '.') ?: '0');
    $price = fn ($x) => \App\Services\ProformaInvoices\PiAmounts::number((string) $x, 2, 4);
@endphp

@section('title', $sourcing->number)

@section('content')
<x-page-header :title="$sourcing->number" :subtitle="$sourcing->client?->name" :back="route('sourcing.index')" :back-label="__('Factory quotes')">
    <x-slot:meta><x-status :tone="SourcingRequest::statusTone($sourcing->status)">{{ SourcingRequest::statusLabel($sourcing->status) }}</x-status></x-slot:meta>
    @if($canEdit)
        <x-slot:actions>
            @if($open)
                <form method="POST" action="{{ route('sourcing.close', $sourcing) }}" onsubmit="return confirm(@js(__('Close without choosing a factory?')));">@csrf<button class="btn btn-light"><i class="bi bi-x-circle" aria-hidden="true"></i>{{ __('Close without choosing') }}</button></form>
            @elseif(! $sourcing->quotation_line_id)
                <form method="POST" action="{{ route('sourcing.reopen', $sourcing) }}">@csrf<button class="btn btn-light"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('Reopen') }}</button></form>
            @endif
        </x-slot:actions>
    @endif
</x-page-header>

@error('request')<div class="alert alert-danger">{{ $message }}</div>@enderror
@error('quote')<div class="alert alert-danger">{{ $message }}</div>@enderror

@if($sourcing->quotationLine)
    <div class="alert alert-success d-flex gap-2 align-items-center"><i class="bi bi-chat-square-quote" aria-hidden="true"></i><div>{{ __('On quotation') }} <a href="{{ route('quotations.show', $sourcing->quotationLine->quotation) }}" class="app-ltr fw-semibold">{{ $sourcing->quotationLine->quotation->isDraft() ? __('Draft') : $sourcing->quotationLine->quotation->number }}</a>, {{ __('line :n', ['n' => $sourcing->quotationLine->line_no]) }}.</div></div>
@endif

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <x-section :title="__('The request')" icon="ui-checks-grid">
            @if($open && $canEdit)
                <form method="POST" action="{{ route('sourcing.update', $sourcing) }}" class="app-form">
                    @csrf @method('PUT')
                    @include('sourcing._form')
                    <div class="mt-3"><button class="btn btn-light"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button></div>
                </form>
            @else
                <x-detail-list :cols="3" :items="[
                    __('Product') => $sourcing->product?->name,
                    __('Quantity') => $sourcing->quantity !== null ? $plain($sourcing->quantity).' '.($units[$sourcing->unit] ?? $sourcing->unit) : null,
                    __('Target factory price') => $sourcing->target_price !== null ? $price($sourcing->target_price).' '.$sourcing->target_currency : null,
                    __('Needed by') => $sourcing->needed_by?->format('Y-m-d'),
                    __('Opened by') => $sourcing->creator?->name,
                ]" />
                <p class="mt-3 mb-0" style="white-space: pre-line" dir="auto">{{ $sourcing->description }}</p>
            @endif
        </x-section>
    </div>
    <div class="col-lg-4">
        <div class="d-flex flex-column gap-3 h-100">
            <div class="flex-fill"><x-kpi :label="__('Quotes')" :value="$quotes->count()" icon="list-ol" /></div>
            @if($cheapest)
                <div class="flex-fill"><x-kpi :label="__('Lowest price')" :value="$price($cheapest->unit_price).' '.$cheapest->currency" icon="award" tone="gold" :hint="$cheapest->supplier?->name" /></div>
            @endif
        </div>
    </div>
</div>

<x-section :title="__('Quotes compared')" icon="ui-checks-grid" flush :subtitle="__('Prices in :book at the latest rate, to compare across currencies.', ['book' => $book])" class="mb-3">
    <div class="table-responsive">
        <table class="table align-middle mb-0 app-ledger">
            <thead><tr>
                <th scope="col">{{ __('Factory') }}</th>
                <th scope="col" class="text-end">{{ __('Unit price') }}</th>
                <th scope="col" class="text-end">{{ __('In :book', ['book' => $book]) }}</th>
                @if($targetInBook !== null)<th scope="col" class="text-end">{{ __('Against target') }}</th>@endif
                <th scope="col" class="text-end">{{ __('MOQ') }}</th>
                <th scope="col" class="text-end">{{ __('Lead time') }}</th>
                <th scope="col">{{ __('Packing') }}</th>
                <th scope="col">{{ __('Valid until') }}</th>
                <th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse($quotes as ['quote' => $q, 'book' => $inBook])
                @php
                    $isChosen = $sourcing->chosen_quote_id === $q->id;
                    $vsTarget = $targetInBook !== null && $inBook !== null && Decimal::isPositive($targetInBook) ? bcdiv(bcmul(Decimal::sub($inBook, $targetInBook, 6), '100', 8), $targetInBook, 1) : null;
                @endphp
                <tr class="{{ $isChosen ? 'table-success' : '' }}">
                    <td>
                        <bdi class="fw-semibold">{{ $q->supplier?->name }}</bdi>
                        @if($cheapest && $cheapest->id === $q->id)<x-status tone="done" icon="award" class="ms-1">{{ __('Lowest') }}</x-status>@endif
                        @if($isChosen)<x-status tone="info" icon="check2-circle" class="ms-1">{{ __('Chosen') }}</x-status>@endif
                        @if($q->note)<div class="small text-muted" dir="auto">{{ $q->note }}</div>@endif
                    </td>
                    <td class="text-end text-nowrap"><span class="app-num fw-semibold">{{ $price($q->unit_price) }}</span> <span class="app-unit">{{ $q->currency }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $inBook !== null ? Decimal::format($inBook, 4) : '—' }}</span></td>
                    @if($targetInBook !== null)<td class="text-end"><span class="app-num {{ $vsTarget !== null && ! str_starts_with($vsTarget, '-') && $vsTarget !== '0.0' ? 'text-danger' : 'text-success' }}">{{ $vsTarget !== null ? ($vsTarget > 0 ? '+' : '').$vsTarget.'%' : '—' }}</span></td>@endif
                    <td class="text-end"><span class="app-num">{{ $plain($q->moq) }}</span></td>
                    <td class="text-end text-nowrap">{{ $q->lead_time_days !== null ? trans_choice('{1} one day|[2,*] :count days', $q->lead_time_days, ['count' => $q->lead_time_days]) : '—' }}</td>
                    <td class="small"><span class="app-num">{{ $q->qty_per_carton !== null ? __(':n per carton', ['n' => $plain($q->qty_per_carton)]) : '' }}</span>@if($q->cbm_per_carton !== null)<div class="app-num text-muted">{{ $plain($q->cbm_per_carton) }} CBM · {{ $plain($q->kg_per_carton) }} KG</div>@endif</td>
                    <td class="text-nowrap"><span class="app-num {{ $q->isExpired() ? 'text-danger' : '' }}">{{ $q->valid_until?->format('Y-m-d') ?? '—' }}</span></td>
                    <td class="text-end text-nowrap">
                        @if($open && $canEdit)
                            <form method="POST" action="{{ route('sourcing.choose', [$sourcing, $q]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary" @disabled($q->isExpired())>{{ __('Choose') }}</button></form>
                            <form method="POST" action="{{ route('sourcing.quotes.destroy', [$sourcing, $q]) }}" class="d-inline" onsubmit="return confirm(@js(__('Remove this quote?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-light" aria-label="{{ __('Remove') }}"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="p-0"><div class="app-panel-empty"><i class="bi bi-ui-checks-grid" aria-hidden="true"></i>{{ __('No quotes yet. Record each factory\'s answer below.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($open && $canEdit)
        <form method="POST" action="{{ route('sourcing.quotes.store', $sourcing) }}" class="p-3 border-top app-form">
            @csrf
            <div class="fw-semibold mb-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> {{ __('Add a factory\'s quote') }}</div>
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label small" for="supplier_id">{{ __('Factory') }}</label>
                    <select id="supplier_id" name="supplier_id" class="form-select form-select-sm @error('supplier_id') is-invalid @enderror" required>
                        <option value="">—</option>
                        @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id') === $s->id)>{{ $s->name }} ({{ $s->code }})</option>@endforeach
                    </select></div>
                <div class="col-6 col-md-2"><label class="form-label small" for="unit_price">{{ __('Unit price') }}</label><input id="unit_price" name="unit_price" value="{{ old('unit_price') }}" inputmode="decimal" class="form-control form-control-sm @error('unit_price') is-invalid @enderror" required></div>
                <div class="col-6 col-md-2"><label class="form-label small" for="currency">{{ __('Currency') }}</label>
                    <select id="currency" name="currency" class="form-select form-select-sm">@foreach($currencies as $code => $label)<option value="{{ $code }}" @selected(old('currency', 'CNY') === $code)>{{ $code }}</option>@endforeach</select></div>
                <div class="col-6 col-md-2"><label class="form-label small" for="moq">{{ __('MOQ') }}</label><input id="moq" name="moq" value="{{ old('moq') }}" inputmode="decimal" class="form-control form-control-sm"></div>
                <div class="col-6 col-md-2"><label class="form-label small" for="lead_time_days">{{ __('Lead time (days)') }}</label><input id="lead_time_days" name="lead_time_days" value="{{ old('lead_time_days') }}" inputmode="numeric" class="form-control form-control-sm @error('lead_time_days') is-invalid @enderror"></div>
                <div class="col-4 col-md-2"><label class="form-label small" for="qty_per_carton">{{ __('Qty per carton') }}</label><input id="qty_per_carton" name="qty_per_carton" value="{{ old('qty_per_carton') }}" inputmode="decimal" class="form-control form-control-sm"></div>
                <div class="col-4 col-md-2"><label class="form-label small" for="cbm_per_carton">{{ __('CBM per carton') }}</label><input id="cbm_per_carton" name="cbm_per_carton" value="{{ old('cbm_per_carton') }}" inputmode="decimal" class="form-control form-control-sm"></div>
                <div class="col-4 col-md-2"><label class="form-label small" for="kg_per_carton">{{ __('KG per carton') }}</label><input id="kg_per_carton" name="kg_per_carton" value="{{ old('kg_per_carton') }}" inputmode="decimal" class="form-control form-control-sm"></div>
                <div class="col-6 col-md-2"><label class="form-label small" for="received_on">{{ __('Received on') }}</label><input id="received_on" type="date" name="received_on" value="{{ old('received_on', now()->toDateString()) }}" class="form-control form-control-sm"></div>
                <div class="col-6 col-md-2"><label class="form-label small" for="valid_until">{{ __('Valid until') }}</label><input id="valid_until" type="date" name="valid_until" value="{{ old('valid_until') }}" class="form-control form-control-sm @error('valid_until') is-invalid @enderror"></div>
                <div class="col-md-10"><label class="form-label small" for="note">{{ __('Note') }}</label><input id="note" name="note" value="{{ old('note') }}" class="form-control form-control-sm" dir="auto"></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add') }}</button></div>
            </div>
            @foreach(['supplier_id', 'unit_price', 'currency', 'moq', 'lead_time_days', 'qty_per_carton', 'cbm_per_carton', 'kg_per_carton', 'received_on', 'valid_until'] as $field)
                @error($field)<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            @endforeach
        </form>
    @endif
</x-section>

@if($sourcing->status === 'chosen' && ! $sourcing->quotation_line_id && $canEdit && Gate::allows('quotations.edit'))
    <x-section :title="__('Quote the client')" icon="chat-square-quote" class="mb-3"
               :subtitle="__('The chosen factory and its price go on the line, with the selling price you give here.')">
        @if($quotations->isEmpty())
            <p class="mb-2">{{ __('There is no draft quotation to add it to. Start one, then come back here.') }}</p>
            <a href="{{ route('quotations.create', $sourcing->client_id ? ['client' => $sourcing->client_id] : []) }}" class="btn btn-light btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New quotation') }}</a>
        @else
            <form method="POST" action="{{ route('sourcing.to-quotation', $sourcing) }}" class="row g-2 align-items-end app-form">
                @csrf
                <div class="col-md-6"><label class="form-label" for="quotation_id">{{ __('Draft quotation') }}</label>
                    <select id="quotation_id" name="quotation_id" class="form-select" required>
                        @foreach($quotations as $q)<option value="{{ $q->id }}">{{ $q->client?->name }} · {{ $q->quoted_on->format('Y-m-d') }} · {{ $q->currency }}</option>@endforeach
                    </select></div>
                <div class="col-md-3"><label class="form-label" for="sale_price">{{ __('Selling price') }}</label><input id="sale_price" name="unit_price" inputmode="decimal" class="form-control @error('unit_price') is-invalid @enderror" required></div>
                <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i>{{ __('Add to the quotation') }}</button></div>
                @error('quotation_id')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
                @error('unit_price')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
            </form>
        @endif
    </x-section>
@endif

@include('partials.attachments', ['attachable' => $sourcing, 'type' => 'sourcing'])
@endsection
