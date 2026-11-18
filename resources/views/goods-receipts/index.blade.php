@extends('layouts.app')

@section('title', __('Goods receipt & inspection'))

@use('App\Services\SupplierOrders\Quantity')

@section('content')
@php
    $canRecord = auth()->user()->can('goods_receipts.edit');
    $results = ['pass' => __('Pass'), 'fail' => __('Fail'), 'pass_with_notes' => __('Pass with notes')];
    $resultTone = ['pass' => ['done', 'check-circle'], 'fail' => ['late', 'x-circle'], 'pass_with_notes' => ['due', 'exclamation-circle']];
    $orderLink = fn ($order) => $canSeeOrders
        ? '<a href="'.e(route('supplier-orders.show', $order)).'" class="app-ltr fw-semibold">'.e($order->number).'</a>'
        : '<span class="app-ltr fw-semibold">'.e($order->number).'</span>';
    $openLine = (int) old('supplier_order_line_id');
    $awaitCols = $canRecord ? 9 : 8;
    $recentCols = $canRecord ? 11 : 10;
@endphp

<x-page-header :title="__('Goods receipt & inspection')" :subtitle="__('Examine & receive goods & update in the system: record the cartons that came in for each line, with the inspection result and what was measured.')">
    <x-slot:meta>
        <span class="app-pill"><i class="bi bi-box-seam" aria-hidden="true"></i>{{ trans_choice('{0} No line awaiting goods|{1} :count line awaiting goods|[2,*] :count lines awaiting goods', $awaiting->total(), ['count' => $awaiting->total()]) }}</span>
    </x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('goods-receipts.stock') }}" class="btn btn-light"><i class="bi bi-house-door" aria-hidden="true"></i>{{ __('In the warehouse') }}</a>
        @can('supplier_orders.view')
            <a href="{{ route('supplier-orders.index') }}" class="btn btn-light"><i class="bi bi-kanban" aria-hidden="true"></i>{{ __('Purchase orders') }}</a>
        @endcan
    </x-slot:actions>
</x-page-header>

@include('supplier-orders._form-errors')

<form method="GET" class="d-flex flex-wrap gap-2 mb-3 align-items-center app-fu-filters" role="search" aria-label="{{ __('Filter') }}">
    @if($seeSuppliers)
        <select name="supplier" class="form-select" aria-label="{{ __('Supplier') }}">
            <option value="">{{ __('All suppliers') }}</option>
            @foreach($suppliers as $s)
                <option value="{{ $s->id }}" @selected($supplier === $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    @endif
    <select name="order" class="form-select" aria-label="{{ __('Order no.') }}">
        <option value="">{{ __('All open orders') }}</option>
        @foreach($orders as $o)
            <option value="{{ $o->id }}" @selected($orderId === $o->id)>{{ $o->number }}</option>
        @endforeach
    </select>
    <button class="btn btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Filter') }}</button>
    @if($supplier || $orderId)
        <a href="{{ route('goods-receipts.index') }}" class="btn btn-link btn-sm">{{ __('Clear filters') }}</a>
    @endif
</form>

<x-section :title="__('Awaiting goods')" icon="hourglass-split" flush class="mb-3"
           :subtitle="__('Earliest delivery date first')">
    <div class="table-responsive app-board">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>{{ __('Order no.') }}</th><th>{{ __('Client / PI') }}</th><th><span class="visually-hidden">{{ __('Photo') }}</span></th><th>{{ __('Item') }}</th>
                <th>{{ __('Delivery date') }}</th>
                <th class="text-end">{{ __('Ordered') }}</th><th>{{ __('Received cartons') }}</th><th class="text-end">{{ __('Awaiting') }}</th>
                @if($canRecord)<th><span class="visually-hidden">{{ __('Actions') }}</span></th>@endif
            </tr></thead>
            <tbody>
            @forelse($awaiting as $line)
                @php
                    $order = $line->supplierOrder;
                    $pi = $line->piLine?->proformaInvoice;
                    $received = (string) ($line->received_cartons ?? '0');
                    $outstanding = bcsub((string) $line->cartons, $received, 4);
                    $isOpen = $openLine === $line->id;
                    $overdue = $order->delivery_date && $order->delivery_date->isPast() && ! $order->delivery_date->isToday();
                @endphp
                <tr @class(['app-fu-hasform', 'is-open' => $isOpen])>
                    <td>
                        {!! $orderLink($order) !!}
                        @if($seeSuppliers)<div class="app-fu-sub"><bdi>{{ $order->supplier?->name }}</bdi></div>@endif
                    </td>
                    <td class="app-fu-wrap" style="min-inline-size:10rem">
                        <bdi>{{ $pi?->client?->name }}</bdi>
                        <div class="app-fu-sub app-ltr">{{ $pi?->reference() }}</div>
                    </td>
                    <td>@include('supplier-orders._photo', ['product' => $line->piLine?->product])</td>
                    <td style="white-space:normal; min-inline-size:12rem">
                        <div class="fw-semibold">{{ $line->piLine?->product?->name }}</div>
                        <div class="app-fu-sub">{{ $line->piLine?->description }}</div>
                    </td>
                    <td>
                        <span class="app-num">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</span>
                        @if($overdue)<div><x-status tone="late">{{ __('Overdue') }}</x-status></div>@endif
                    </td>
                    <td class="app-num text-end">{{ Quantity::trim($line->cartons) }}</td>
                    <td class="app-fu-qty">
                        <x-progress :value="$received" :max="$line->cartons" thin :caption="Quantity::trim($received).' / '.Quantity::trim($line->cartons)" />
                    </td>
                    <td class="app-num text-end fw-semibold">{{ Quantity::trim($outstanding) }}</td>
                    @if($canRecord)
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#receive-{{ $line->id }}"
                                    aria-controls="receive-{{ $line->id }}" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                                <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>{{ __('Receive') }}
                            </button>
                        </td>
                    @endif
                </tr>
                @if($canRecord)
                    <tr class="app-fu-formrow collapse @if($isOpen) show @endif" id="receive-{{ $line->id }}">
                        <td colspan="{{ $awaitCols }}">
                            <form method="POST" action="{{ route('goods-receipts.store') }}" class="app-fu-formrow__inner">
                                @csrf
                                <input type="hidden" name="supplier_order_line_id" value="{{ $line->id }}">
                                <div class="app-fu-panel-title">{{ __('Receive :item', ['item' => $line->piLine?->product?->name ?: $line->piLine?->description]) }}</div>
                                <div class="row g-2">
                                    <div class="col-6 col-md-3 col-lg-2">
                                        <label class="form-label" for="r{{ $line->id }}-date">{{ __('Date') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                        <input id="r{{ $line->id }}-date" type="date" name="received_on" required max="{{ now()->toDateString() }}" class="form-control form-control-sm @if($isOpen && $errors->has('received_on')) is-invalid @endif" value="{{ $isOpen ? old('received_on', now()->toDateString()) : now()->toDateString() }}">
                                        @if($isOpen)@error('received_on')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                    </div>
                                    <div class="col-6 col-md-3 col-lg-2">
                                        <label class="form-label" for="r{{ $line->id }}-cartons">{{ __('Cartons') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                        <input id="r{{ $line->id }}-cartons" type="number" name="cartons" required step="0.0001" min="0.0001" class="form-control form-control-sm @if($isOpen && $errors->has('cartons')) is-invalid @endif" dir="ltr" value="{{ $isOpen ? old('cartons', Quantity::trim($outstanding)) : Quantity::trim($outstanding) }}">
                                        @if($isOpen)@error('cartons')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-3">
                                        <label class="form-label" for="r{{ $line->id }}-result">{{ __('Inspection result') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                        <select id="r{{ $line->id }}-result" name="inspection_result" required class="form-select form-select-sm @if($isOpen && $errors->has('inspection_result')) is-invalid @endif">
                                            @foreach($results as $value => $label)
                                                <option value="{{ $value }}" @selected($isOpen && old('inspection_result') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @if($isOpen)@error('inspection_result')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                    </div>
                                    <div class="col-6 col-md-3 col-lg-2">
                                        <label class="form-label" for="r{{ $line->id }}-cbm">{{ __('Measured CBM') }}</label>
                                        <input id="r{{ $line->id }}-cbm" type="number" name="measured_cbm" step="0.0001" min="0" class="form-control form-control-sm" dir="ltr" value="{{ $isOpen ? old('measured_cbm') : '' }}">
                                    </div>
                                    <div class="col-6 col-md-3 col-lg-3">
                                        <label class="form-label" for="r{{ $line->id }}-kg">{{ __('Measured KG') }}</label>
                                        <input id="r{{ $line->id }}-kg" type="number" name="measured_kg" step="0.0001" min="0" class="form-control form-control-sm" dir="ltr" value="{{ $isOpen ? old('measured_kg') : '' }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-4">
                                        <label class="form-label" for="r{{ $line->id }}-location">{{ __('Location') }}</label>
                                        <input id="r{{ $line->id }}-location" name="location" maxlength="120" class="form-control form-control-sm" value="{{ $isOpen ? old('location') : '' }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-lg-8">
                                        <label class="form-label" for="r{{ $line->id }}-note">{{ __('Note') }}</label>
                                        <input id="r{{ $line->id }}-note" name="note" maxlength="2000" class="form-control form-control-sm" value="{{ $isOpen ? old('note') : '' }}">
                                    </div>
                                </div>
                                <div class="app-fu-formactions mt-3">
                                    <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>{{ __('Record receipt') }}</button>
                                    <button type="button" class="btn btn-sm btn-link" data-bs-toggle="collapse" data-bs-target="#receive-{{ $line->id }}">{{ __('Cancel') }}</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="{{ $awaitCols }}" class="p-0">
                    <x-empty icon="check2-circle" :title="__('No open order line is awaiting goods.')" :text="__('Every carton ordered on an open order has come in.')" />
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($awaiting->hasPages())<div class="p-3 border-top">{{ $awaiting->links() }}</div>@endif
</x-section>

<x-section :title="__('Recent receipts')" icon="clock-history" flush>
    <div class="table-responsive app-board">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>{{ __('Date') }}</th><th>{{ __('Order no.') }}</th><th>{{ __('Item') }}</th>
                <th class="text-end">{{ __('Cartons') }}</th><th>{{ __('Inspection') }}</th>
                <th class="text-end">{{ __('CBM') }}</th><th class="text-end">{{ __('KG') }}</th><th>{{ __('Location') }}</th><th>{{ __('Note') }}</th><th>{{ __('By') }}</th>
                @if($canRecord)<th><span class="visually-hidden">{{ __('Actions') }}</span></th>@endif
            </tr></thead>
            <tbody>
            @forelse($recent as $receipt)
                @php
                    $held = bcadd((string) $receipt->cartons, (string) ($receipt->corrected_cartons ?? '0'), 4);
                    $canCorrect = $canRecord && ! $receipt->isCorrection() && bccomp($held, '0', 4) > 0;
                @endphp
                <tr @class(['app-fu-hasform'])>
                    <td class="app-num">{{ $receipt->received_on?->format('Y-m-d') }}</td>
                    <td>{!! $orderLink($receipt->line->supplierOrder) !!}</td>
                    <td style="white-space:normal; min-inline-size:10rem">{{ $receipt->line->piLine?->product?->name }}</td>
                    <td class="app-num text-end {{ $receipt->isCorrection() ? 'text-danger fw-semibold' : '' }}">{{ Quantity::trim($receipt->cartons) }}</td>
                    <td>
                        @if($receipt->isCorrection())
                            <x-status icon="arrow-return-left">{{ __('Correction of the receipt of :date', ['date' => $receipt->corrects?->received_on?->format('Y-m-d')]) }}</x-status>
                        @else
                            @php [$tone, $icon] = $resultTone[$receipt->inspection_result] ?? ['neutral', 'circle']; @endphp
                            <x-status :tone="$tone" :icon="$icon">{{ $results[$receipt->inspection_result] ?? $receipt->inspection_result }}</x-status>
                        @endif
                    </td>
                    <td class="app-num text-end">{{ $receipt->measured_cbm !== null ? Quantity::trim($receipt->measured_cbm) : '' }}</td>
                    <td class="app-num text-end">{{ $receipt->measured_kg !== null ? Quantity::trim($receipt->measured_kg) : '' }}</td>
                    <td>{{ $receipt->location }}</td>
                    <td class="small" style="white-space:normal; min-inline-size:8rem">{{ $receipt->note }}</td>
                    <td>{{ $receipt->user?->name }}</td>
                    @if($canRecord)
                        <td class="text-end">
                            @if($canCorrect)
                                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#correct-{{ $receipt->id }}" aria-controls="correct-{{ $receipt->id }}" aria-expanded="false">
                                    <i class="bi bi-arrow-return-left" aria-hidden="true"></i>{{ __('Correct') }}
                                </button>
                            @endif
                        </td>
                    @endif
                </tr>
                @if($canCorrect)
                    <tr class="app-fu-formrow collapse" id="correct-{{ $receipt->id }}">
                        <td colspan="{{ $recentCols }}">
                            <form method="POST" action="{{ route('goods-receipts.correct', $receipt) }}" class="app-fu-formrow__inner">
                                @csrf
                                <div class="app-fu-panel-title">{{ __('Correct the receipt of :date', ['date' => $receipt->received_on?->format('Y-m-d')]) }}</div>
                                <div class="row g-2 align-items-end">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label" for="c{{ $receipt->id }}-cartons">{{ __('Cartons to take off') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                        <input id="c{{ $receipt->id }}-cartons" type="number" name="cartons" required step="0.0001" min="0.0001" class="form-control form-control-sm" dir="ltr">
                                        <div class="form-text">{{ __('Up to :count', ['count' => Quantity::trim($held)]) }}</div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label" for="c{{ $receipt->id }}-note">{{ __('Reason') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                        <input id="c{{ $receipt->id }}-note" name="note" required maxlength="2000" class="form-control form-control-sm" placeholder="{{ __('Reason') }}">
                                        <div class="form-text">&nbsp;</div>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <div class="app-fu-formactions">
                                            <button class="btn btn-sm btn-primary">{{ __('Save correction') }}</button>
                                            <button type="button" class="btn btn-sm btn-link" data-bs-toggle="collapse" data-bs-target="#correct-{{ $receipt->id }}">{{ __('Cancel') }}</button>
                                        </div>
                                        <div class="form-text">&nbsp;</div>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="{{ $recentCols }}" class="p-0">
                    <x-empty icon="inbox" :title="__('No goods received yet.')" />
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection

@push('scripts')
<script>
    // A form under a row: mark the row while it is open, so the two read as one.
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('tr.app-fu-formrow').forEach((row) => {
            const owner = row.previousElementSibling;
            row.addEventListener('show.bs.collapse', () => owner?.classList.add('is-open'));
            row.addEventListener('hidden.bs.collapse', () => owner?.classList.remove('is-open'));
        });
        @if($errors->any())
            (document.querySelector('tr.app-fu-formrow.show .is-invalid') || document.getElementById('form-errors'))?.focus();
        @endif
    });
</script>
@endpush
