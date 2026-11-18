@extends('layouts.app')

@php
    use App\Models\Quotation;
    use App\Services\Accounting\Decimal;
    $st = $quotation->displayStatus();
    $title = $quotation->isDraft() ? __('Quotation (draft)') : __('Quotation :number', ['number' => $quotation->number]);
    $f = fn ($v) => Decimal::format($v);
    $plain = fn ($v) => $v === null ? '—' : (rtrim(rtrim((string) $v, '0'), '.') ?: '0');
    $editable = $quotation->isDraft() && Gate::allows('quotations.edit');
    $total = $quotation->lines->filter(fn ($l) => $l->cartons !== null)->reduce(fn ($c, $l) => bcadd($c, $l->amount(), 4), '0');
@endphp

@section('title', $title)

@section('content')
<x-page-header :title="$title" :subtitle="$quotation->client?->name" :back="route('quotations.index')" :back-label="__('Quotations')">
    <x-slot:meta><x-status :tone="Quotation::statusTone($st)">{{ Quotation::statusLabel($st) }}</x-status></x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('quotations.print', $quotation) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer" aria-hidden="true"></i>{{ __('Print') }}</a>
        @unless($quotation->isDraft())
            <a href="{{ route('documents.send.create', ['type' => 'quotation', 'id' => $quotation->id]) }}" class="btn btn-light"><i class="bi bi-envelope" aria-hidden="true"></i>{{ __('Email or WhatsApp') }}</a>
        @endunless
        @can('quotations.edit')
            @if($quotation->isDraft())
                <form method="POST" action="{{ route('quotations.send', $quotation) }}">@csrf<button class="btn btn-primary"><i class="bi bi-send" aria-hidden="true"></i>{{ __('Send') }}</button></form>
            @elseif($quotation->status === 'sent')
                <form method="POST" action="{{ route('quotations.accept', $quotation) }}">@csrf<button class="btn btn-light"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i>{{ __('Accepted by the client') }}</button></form>
                <form method="POST" action="{{ route('quotations.decline', $quotation) }}">@csrf<button class="btn btn-light"><i class="bi bi-hand-thumbs-down" aria-hidden="true"></i>{{ __('Declined') }}</button></form>
            @endif
            @if(in_array($quotation->status, ['sent', 'accepted'], true) && ! $quotation->proforma_invoice_id && Gate::allows('proforma_invoices.edit'))
                <form method="POST" action="{{ route('quotations.convert', $quotation) }}" onsubmit="return confirm(@js(__('Make a draft PI from this quotation?')));">@csrf<button class="btn btn-primary"><i class="bi bi-receipt" aria-hidden="true"></i>{{ __('Make the PI') }}</button></form>
            @endif
            @unless($quotation->isDraft())
                <form method="POST" action="{{ route('quotations.duplicate', $quotation) }}">@csrf<button class="btn btn-light"><i class="bi bi-files" aria-hidden="true"></i>{{ __('Copy to revise') }}</button></form>
            @endunless
        @endcan
    </x-slot:actions>
</x-page-header>

@error('quotation')<div class="alert alert-danger">{{ $message }}</div>@enderror
@if($quotation->proformaInvoice)
    <div class="alert alert-success d-flex gap-2 align-items-center"><i class="bi bi-receipt" aria-hidden="true"></i><div>{{ __('This quotation became PI') }} <a href="{{ route('proforma-invoices.show', $quotation->proforma_invoice_id) }}" class="app-ltr fw-semibold">{{ $quotation->proformaInvoice->reference() }}</a>.</div></div>
@endif

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <x-section :title="__('Quotation')" icon="chat-square-quote">
            @if($editable)
                <form method="POST" action="{{ route('quotations.update', $quotation) }}" class="app-form">
                    @csrf @method('PUT')
                    @include('quotations._header-form', ['preset' => null])
                    <div class="mt-3 d-flex gap-2">
                        <button class="btn btn-light"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
                    </div>
                </form>
            @else
                <x-detail-list :cols="3" :items="[
                    __('Client') => $quotation->client?->name,
                    __('Date') => $quotation->quoted_on->format('Y-m-d'),
                    __('Valid until') => $quotation->valid_until->format('Y-m-d'),
                    __('Currency') => $quotation->currency,
                    __('Incoterm') => $quotation->incoterm,
                    __('Sent') => $quotation->sent_at?->format('Y-m-d'),
                ]" />
                @if($quotation->terms)<p class="mt-3 mb-0 small" dir="ltr">{{ $quotation->terms }}</p>@endif
                @if($quotation->notes)<p class="mt-2 mb-0 small text-muted" dir="auto"><i class="bi bi-lock" aria-hidden="true"></i> {{ $quotation->notes }}</p>@endif
            @endif
        </x-section>
    </div>
    <div class="col-lg-4">
        <div class="d-flex flex-column gap-3 h-100">
            <div class="flex-fill"><x-kpi :label="__('Items quoted')" :value="$quotation->lines->count()" icon="list-ul" /></div>
            @if(Decimal::isPositive($total))
                <div class="flex-fill"><x-kpi :label="__('Value of the quantities asked')" :value="$f($total).' '.$quotation->currency" icon="cash-stack" /></div>
            @endif
        </div>
    </div>
</div>

<x-section :title="__('Lines')" icon="list-ul" flush id="lines">
    @if($quotation->lines->isEmpty())
        <div class="app-panel-empty"><i class="bi bi-list-ul" aria-hidden="true"></i>{{ __('No lines yet.') }}</div>
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th scope="col">{{ __('No.') }}</th><th scope="col">{{ __('Item name & description') }}</th><th scope="col">{{ __('Unit') }}</th>
                    <th scope="col" class="text-end">{{ __('Qty per carton') }}</th><th scope="col" class="text-end">{{ __('MOQ') }}</th>
                    <th scope="col" class="text-end">{{ __('Unit price') }}</th><th scope="col" class="text-end">{{ __('Cartons') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th>
                    @if($seesSupplier)<th scope="col" class="text-end"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('Supplier price') }}</th>@endif
                    @if($editable)<th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>@endif
                </tr></thead>
                <tbody>
                @foreach($quotation->lines as $line)
                    <tr>
                        <td><span class="app-num">{{ $line->line_no }}</span></td>
                        <td style="white-space: pre-line; min-inline-size: 14rem">{{ $line->description }}@if($line->product)<span class="d-block small text-muted"><span class="app-ltr">{{ $line->product->code }}</span></span>@endif</td>
                        <td>{{ $units[$line->unit] ?? $line->unit }}</td>
                        <td class="text-end"><span class="app-num">{{ $plain($line->qty_per_carton) }}</span></td>
                        <td class="text-end"><span class="app-num">{{ $plain($line->moq) }}</span></td>
                        <td class="text-end"><span class="app-num fw-semibold">{{ \App\Services\ProformaInvoices\PiAmounts::number((string) $line->unit_price, 2, 4) }}</span></td>
                        <td class="text-end"><span class="app-num">{{ $plain($line->cartons) }}</span></td>
                        <td class="text-end"><span class="app-num">{{ $line->amount() ? $f($line->amount()) : '—' }}</span></td>
                        @if($seesSupplier)<td class="text-end"><span class="app-num">{{ $line->supplier_price !== null ? \App\Services\ProformaInvoices\PiAmounts::number((string) $line->supplier_price, 2, 4).' '.$line->supplier_currency : '—' }}</span>@if($line->supplier)<div class="small text-muted">{{ $line->supplier->name }}</div>@endif</td>@endif
                        @if($editable)
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-light" data-toggle-row="ql-{{ $line->id }}" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}: {{ $line->line_no }}"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                <form method="POST" action="{{ route('quotations.lines.destroy', [$quotation, $line]) }}" class="d-inline" onsubmit="return confirm(@js(__('Remove this line?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-light" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}: {{ $line->line_no }}"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
                            </td>
                        @endif
                    </tr>
                    @if($editable)
                        <tr id="ql-{{ $line->id }}" class="d-none app-q-editrow">
                            <td colspan="{{ $seesSupplier ? 10 : 9 }}">
                                <form method="POST" action="{{ route('quotations.lines.update', [$quotation, $line]) }}">
                                    @csrf @method('PUT')
                                    @include('quotations._line-form', ['line' => $line, 'prefix' => 'ql'.$line->id])
                                    <button class="btn btn-sm btn-primary mt-2"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if($editable)
        <form method="POST" action="{{ route('quotations.lines.store', $quotation) }}" class="app-hr-inlineform border-top">
            @csrf
            <div class="app-hr-inlineform__title"><i class="bi bi-plus-circle" aria-hidden="true"></i>{{ __('Add a line') }}</div>
            @foreach(['product_id', 'description', 'unit', 'unit_price', 'qty_per_carton', 'moq', 'cartons', 'cbm_per_carton', 'kg_per_carton', 'supplier_id', 'supplier_price'] as $field)
                @error($field)<div class="text-danger small">{{ $message }}</div>@enderror
            @endforeach
            @include('quotations._line-form', ['line' => null, 'prefix' => 'qn'])
            <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add the line') }}</button>
        </form>
    @endif
</x-section>

@if($editable && \App\Services\Ai\Drafts\TradeDrafts::enabled('quotation_from_message'))
    @php $aiLines = session('ai_lines'); $unitOptions = \App\Models\Lookup::options('unit'); @endphp
    <x-section :title="__('Draft lines from the client\'s message')" icon="stars" class="mt-3" :subtitle="__('Paste the client\'s email or WhatsApp message: the AI proposes the lines, you check and price them before anything is added.')">
        @if(is_array($aiLines) && count($aiLines))
            <form method="POST" action="{{ route('quotations.ai.add', $quotation) }}">
                @csrf
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th scope="col"><span class="visually-hidden">{{ __('Add') }}</span></th><th scope="col">{{ __('Item name & description') }}</th><th scope="col">{{ __('Unit') }}</th><th scope="col">{{ __('MOQ') }}</th><th scope="col">{{ __('Unit price') }}</th></tr></thead>
                        <tbody>
                        @foreach($aiLines as $i => $l)
                            <tr>
                                <td><input type="checkbox" class="form-check-input" name="lines[{{ $i }}][take]" value="1" checked aria-label="{{ __('Add') }}"></td>
                                <td><textarea name="lines[{{ $i }}][description]" rows="2" class="form-control form-control-sm" dir="auto">{{ $l['description'] }}</textarea>@if($l['note'])<div class="small text-muted">{{ $l['note'] }}</div>@endif</td>
                                <td><select name="lines[{{ $i }}][unit]" class="form-select form-select-sm">@foreach($unitOptions as $code => $label)<option value="{{ $code }}" @selected(($l['unit'] ?? 'pcs') === $code)>{{ $label }}</option>@endforeach</select></td>
                                <td><input name="lines[{{ $i }}][quantity]" value="{{ $l['quantity'] }}" inputmode="decimal" class="form-control form-control-sm" style="max-inline-size: 7rem"></td>
                                <td><input name="lines[{{ $i }}][unit_price]" value="{{ $l['target_price'] }}" inputmode="decimal" class="form-control form-control-sm" style="max-inline-size: 7rem" placeholder="0"></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add the ticked lines') }}</button>
            </form>
        @else
            <form method="POST" action="{{ route('quotations.ai.read', $quotation) }}">
                @csrf
                <textarea name="message" rows="4" class="form-control mb-2" dir="auto" required aria-label="{{ __('The client\'s message') }}">{{ old('message') }}</textarea>
                <button class="btn btn-light btn-sm"><i class="bi bi-stars" aria-hidden="true"></i>{{ __('Read it') }}</button>
            </form>
        @endif
    </x-section>
@endif

@if($editable)
    <form method="POST" action="{{ route('quotations.destroy', $quotation) }}" class="mt-3" onsubmit="return confirm(@js(__('Delete this draft?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i>{{ __('Delete the draft') }}</button></form>
@endif
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-toggle-row]').forEach((b) => b.addEventListener('click', () => document.getElementById(b.dataset.toggleRow)?.classList.toggle('d-none')));
</script>
@endpush
