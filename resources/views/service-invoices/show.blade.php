@extends('layouts.app')

@php use App\Services\Accounting\Decimal; $plain = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

@section('title', $invoice->isDraft() ? __('Invoice (draft)') : $invoice->number)

@section('content')
<x-page-header :title="$invoice->isDraft() ? __('Invoice (draft)') : __('Invoice :number', ['number' => $invoice->number])" :subtitle="$invoice->client?->name" :back="route('service-invoices.index')" :back-label="__('Service invoices')">
    <x-slot:meta><x-status :tone="['issued' => 'done', 'void' => 'skip'][$invoice->status] ?? 'neutral'">{{ ['draft' => __('Draft'), 'issued' => __('Issued'), 'void' => __('Void (note)')][$invoice->status] }}</x-status></x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('service-invoices.print', $invoice) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer" aria-hidden="true"></i>{{ __('Print') }}</a>
        @if($invoice->status === 'issued')<a href="{{ route('documents.send.create', ['type' => 'service-invoice', 'id' => $invoice->id]) }}" class="btn btn-light"><i class="bi bi-send" aria-hidden="true"></i>{{ __('Send') }}</a>@endif
        @if($invoice->isDraft())
            @can('service_invoices.approve')
                <form method="POST" action="{{ route('service-invoices.issue', $invoice) }}" class="d-flex gap-1" onsubmit="return confirm(@js(__('Issue this invoice? It never changes after.')));">@csrf
                    <input type="date" name="issued_on" value="{{ now()->toDateString() }}" class="form-control" aria-label="{{ __('Date') }}" style="max-inline-size: 11rem">
                    <input type="date" name="due_on" class="form-control" aria-label="{{ __('Due on') }}" title="{{ __('Due on') }}" style="max-inline-size: 11rem">
                    <button class="btn btn-primary"><i class="bi bi-patch-check" aria-hidden="true"></i>{{ __('Issue') }}</button></form>
            @endcan
        @endif
    </x-slot:actions>
</x-page-header>
@error('invoice')<div class="alert alert-danger">{{ $message }}</div>@enderror

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <x-section :title="__('The invoice')" icon="receipt">
            @if($editable)
                <form method="POST" action="{{ route('service-invoices.update', $invoice) }}" class="app-form">@csrf @method('PUT')
                    @include('service-invoices._header', ['preset' => []])
                    <div class="mt-3"><button class="btn btn-light"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button></div></form>
            @else
                <x-detail-list :cols="2" :items="array_filter([__('Client') => $invoice->client?->name, __('PI') => $invoice->proformaInvoice?->reference(), __('Date') => $invoice->issued_on?->format('Y-m-d'), __('Due on') => $invoice->due_on?->format('Y-m-d')], fn ($v) => $v !== null)" />
                @if($invoice->notes)<p class="mt-2 mb-0" dir="auto">{{ $invoice->notes }}</p>@endif
                @if($invoice->status === 'void')<div class="alert alert-secondary mt-3 mb-0">{{ $invoice->void_reason }}</div>@endif
            @endif
        </x-section>
    </div>
    <div class="col-lg-4"><x-kpi :label="__('Total')" :value="Decimal::format($total).' '.$invoice->currency" icon="cash-stack" tone="gold" /></div>
</div>

<x-section :title="__('Lines')" icon="list-ul" flush>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th scope="col">{{ __('No.') }}</th><th scope="col">{{ __('For') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="text-end">{{ __('Quantity') }}</th><th scope="col" class="text-end">{{ __('Unit price') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th>@if($editable)<th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>@endif</tr></thead>
            <tbody>
            @forelse($invoice->lines as $l)
                <tr><td><span class="app-num">{{ $l->line_no }}</span></td><td>{{ $categories[$l->category] ?? $l->category }}</td><td dir="auto">{{ $l->description }}</td>
                    <td class="text-end"><span class="app-num">{{ $plain($l->quantity) }}</span></td><td class="text-end"><span class="app-num">{{ Decimal::format((string) $l->unit_price) }}</span></td>
                    <td class="text-end"><span class="app-num fw-semibold">{{ Decimal::format((string) $l->amount) }}</span></td>
                    @if($editable)<td class="text-end"><form method="POST" action="{{ route('service-invoices.lines.destroy', [$invoice, $l]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-light" aria-label="{{ __('Remove') }}"><i class="bi bi-trash" aria-hidden="true"></i></button></form></td>@endif</tr>
            @empty
                <tr><td colspan="7" class="p-0"><div class="app-panel-empty">{{ __('No lines yet.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($editable)
        <form method="POST" action="{{ route('service-invoices.lines.store', $invoice) }}" class="row g-2 align-items-end p-3 border-top">@csrf
            {{-- Each field says what is wrong with it, under it (the brief's "refused without an error message"). --}}
            <div class="col-md-3"><label class="form-label small" for="category">{{ __('For') }}</label><select id="category" name="category" class="form-select form-select-sm @error('category') is-invalid @enderror">@foreach($categories as $code => $label)<option value="{{ $code }}" @selected(old('category') === $code)>{{ $label }}</option>@endforeach</select>@error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-4"><label class="form-label small" for="description">{{ __('Description') }}</label><input id="description" name="description" value="{{ old('description') }}" class="form-control form-control-sm @error('description') is-invalid @enderror" required dir="auto">@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-1"><label class="form-label small" for="quantity">{{ __('Quantity') }}</label><input id="quantity" name="quantity" value="{{ old('quantity', '1') }}" inputmode="decimal" class="form-control form-control-sm @error('quantity') is-invalid @enderror">@error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-2"><label class="form-label small" for="unit_price">{{ __('Unit price') }}</label><input id="unit_price" name="unit_price" value="{{ old('unit_price') }}" inputmode="decimal" class="form-control form-control-sm @error('unit_price') is-invalid @enderror" required>@error('unit_price')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-2 align-self-start pt-4"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add the line') }}</button></div>
        </form>
    @endif
</x-section>

@if($invoice->status === 'issued')
    @can('service_invoices.approve')
        <form method="POST" action="{{ route('service-invoices.void', $invoice) }}" class="app-card p-3 mt-3 d-flex gap-2 flex-wrap" onsubmit="return confirm(@js(__('Void this invoice?')));">@csrf
            <input name="void_reason" class="form-control" style="flex: 1 1 18rem" placeholder="{{ __('Why it is void') }}" aria-label="{{ __('Why it is void') }}" required dir="auto">
            <button class="btn btn-outline-danger"><i class="bi bi-x-octagon" aria-hidden="true"></i>{{ __('Void') }}</button></form>
    @endcan
@endif
@if($editable)
    <form method="POST" action="{{ route('service-invoices.destroy', $invoice) }}" class="mt-3" onsubmit="return confirm(@js(__('Delete this draft?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i>{{ __('Delete the draft') }}</button></form>
@endif
@include('partials.attachments', ['attachable' => $invoice, 'type' => 'service-invoices'])
@endsection
