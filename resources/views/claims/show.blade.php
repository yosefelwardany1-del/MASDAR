@extends('layouts.app')

@php
    use App\Models\Claim;
    use App\Models\TradeNote;
    use App\Services\Accounting\Decimal;
    $st = $claim->status();
    $plain = fn ($v) => $v === null ? null : (rtrim(rtrim((string) $v, '0'), '.') ?: '0');
    $canEdit = Gate::allows('claims.edit');
@endphp

@section('title', __('Claim :number', ['number' => $claim->number]))

@section('content')
<x-page-header :title="__('Claim :number', ['number' => $claim->number])" :subtitle="$kinds[$claim->kind] ?? $claim->kind" :back="route('claims.index')" :back-label="__('Claims')">
    <x-slot:meta><x-status :tone="Claim::statusTone($st)">{{ Claim::statusLabel($st) }}</x-status></x-slot:meta>
    <x-slot:actions>
        @if($canEdit && ! $claim->isFinished())
            <a href="{{ route('claims.edit', $claim) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
        @endif
        @if($canEdit && $claim->supplier_id && $seesSuppliers)
            <a href="{{ route('trade-notes.create', ['kind' => 'debit', 'claim' => $claim->id]) }}" class="btn btn-light"><i class="bi bi-arrow-return-right" aria-hidden="true"></i>{{ __('Raise a debit note on the factory') }}</a>
        @endif
        @if($canEdit && $claim->client_id)
            <a href="{{ route('trade-notes.create', ['kind' => 'credit', 'claim' => $claim->id]) }}" class="btn btn-light"><i class="bi bi-arrow-return-left" aria-hidden="true"></i>{{ __('Give the client a credit note') }}</a>
        @endif
    </x-slot:actions>
</x-page-header>

@error('claim')<div class="alert alert-danger">{{ $message }}</div>@enderror
@error('status')<div class="alert alert-danger">{{ $message }}</div>@enderror

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <x-section :title="__('The claim')" icon="exclamation-octagon">
            <x-detail-list :cols="2" :items="array_filter([
                __('Factory') => $seesSuppliers ? $claim->supplier?->name : null,
                __('Purchase order') => $seesSuppliers && $claim->supplierOrder ? new \Illuminate\Support\HtmlString('<a class=\'app-ltr\' href=\''.e(route('supplier-orders.show', $claim->supplierOrder)).'\'>'.e($claim->supplierOrder->number).'</a>') : null,
                __('Client') => $claim->client?->name,
                __('PI') => $claim->proformaInvoice ? new \Illuminate\Support\HtmlString('<a class=\'app-ltr\' href=\''.e(route('proforma-invoices.show', $claim->proformaInvoice)).'\'>'.e($claim->proformaInvoice->reference()).'</a>') : null,
                __('Opened on') => $claim->opened_on->format('Y-m-d'),
                __('Opened by') => $claim->creator?->name,
                __('Cartons affected') => $plain($claim->cartons),
                __('Amount claimed') => $claim->claimed_amount !== null ? Decimal::format((string) $claim->claimed_amount).' '.$claim->currency : null,
            ], fn ($v) => $v !== null)" />
            <p class="mt-3 mb-0" style="white-space: pre-line" dir="auto">{{ $claim->description }}</p>
        </x-section>
    </div>
    <div class="col-lg-4">
        <x-section :title="__('Move the claim')" icon="signpost-split">
            @if($canEdit && count($moves))
                <form method="POST" action="{{ route('claims.move', $claim) }}" class="app-form">
                    @csrf
                    <label class="form-label" for="status">{{ __('New status') }}</label>
                    <select id="status" name="status" class="form-select mb-2" required>
                        @foreach($moves as $to)<option value="{{ $to }}">{{ $to === 'open' && $claim->isFinished() ? __('Reopen') : Claim::statusLabel($to) }}</option>@endforeach
                    </select>
                    <label class="form-label" for="agreed_amount">{{ __('Amount agreed (when agreed)') }}</label>
                    <input id="agreed_amount" name="agreed_amount" inputmode="decimal" class="form-control mb-2" placeholder="{{ $claim->currency }}">
                    <label class="form-label" for="note">{{ __('Note') }}</label>
                    <textarea id="note" name="note" rows="2" class="form-control mb-2" dir="auto"></textarea>
                    <button class="btn btn-primary w-100"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
                </form>
            @else
                <p class="text-muted mb-0">{{ __('Nothing to change here.') }}</p>
            @endif
        </x-section>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <x-section :title="__('History')" icon="clock-history" flush>
            <ol class="list-unstyled mb-0">
                @foreach($claim->events->reverse() as $e)
                    <li class="px-3 py-2 border-bottom">
                        <x-status :tone="Claim::statusTone($e->status)">{{ Claim::statusLabel($e->status) }}</x-status>
                        @if($e->agreed_amount !== null)<span class="app-num ms-1">{{ Decimal::format((string) $e->agreed_amount) }} {{ $claim->currency }}</span>@endif
                        <span class="small text-muted ms-1">{{ $e->user?->name }} · <span class="app-num">{{ $e->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span></span>
                        @if($e->note)<div class="small mt-1" dir="auto">{{ $e->note }}</div>@endif
                    </li>
                @endforeach
            </ol>
        </x-section>
    </div>
    <div class="col-lg-6">
        <x-section :title="__('Notes that settle it')" icon="arrow-left-right" flush>
            @php $notes = $claim->notes->filter(fn ($n) => $n->isCredit() || $seesSuppliers); @endphp
            @forelse($notes as $n)
                <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom">
                    <a href="{{ route('trade-notes.show', $n) }}" class="app-ltr fw-semibold">{{ $n->isDraft() ? __('Draft') : $n->number }}</a>
                    <span class="small text-muted">{{ $n->kindLabel() }}</span>
                    <span class="ms-auto app-num">{{ Decimal::format(Decimal::sum($n->lines->pluck('amount')->map(fn ($v) => (string) $v))) }} {{ $n->currency }}</span>
                    <x-status :tone="TradeNote::statusTone($n->status)">{{ TradeNote::statusLabel($n->status) }}</x-status>
                </div>
            @empty
                <div class="app-panel-empty"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>{{ __('No note yet. Once the factory or the client agrees, settle the claim with a note.') }}</div>
            @endforelse
        </x-section>
    </div>
</div>

@include('partials.attachments', ['attachable' => $claim, 'type' => 'claims'])
@endsection
