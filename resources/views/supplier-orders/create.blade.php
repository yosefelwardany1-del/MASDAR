@extends('layouts.app')

@section('title', __('New supplier orders'))

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h4 mb-0">{{ __('New supplier orders from :pi', ['pi' => $pi->reference()]) }}</h1>
    <a href="{{ route('supplier-orders.index') }}" class="btn btn-light">{{ __('Back to the board') }}</a>
</div>
<p class="text-muted mb-3">
    {{ __('Client') }}: <span class="fw-semibold">{{ $pi->client?->name }}</span>.
    {{ __('One supplier order is made per supplier. Lines are grouped by the supplier planned on the PI; pick another supplier for a group if needed.') }}
</p>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

@if($groups->isEmpty())
    <div class="app-card p-4 text-muted">{{ __('Every line of this PI is already on a supplier order.') }}</div>
@else
<form method="POST" action="{{ route('supplier-orders.store') }}">
    @csrf
    <input type="hidden" name="pi" value="{{ $pi->id }}">
    @foreach($groups as $plannedId => $lines)
        @php
            $planned = $lines->first()->supplier;
            $key = "groups.{$plannedId}.";
            $name = fn (string $field) => "groups[{$plannedId}][{$field}]";
            $lineCurrencies = $lines->pluck('supplier_currency')->filter()->unique();
            $currency = $lineCurrencies->count() === 1 ? $lineCurrencies->first() : $defaultCurrency;
        @endphp
        <div class="app-card p-3 mb-3">
            <h2 class="h6 mb-3">
                @if($planned)
                    {{ __('Planned supplier: :name', ['name' => $planned->name]) }}
                @else
                    {{ __('No supplier planned on the PI') }}
                @endif
                <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">{{ trans_choice('{1} :count line|[2,*] :count lines', $lines->count(), ['count' => $lines->count()]) }}</span>
            </h2>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label" for="g{{ $plannedId }}-supplier">{{ __('Supplier') }} <span class="text-danger">*</span></label>
                    <select id="g{{ $plannedId }}-supplier" name="{{ $name('supplier_id') }}" class="form-select">
                        <option value="">{{ __('Pick a supplier') }}</option>
                        @foreach($suppliers as $s)
                            <option value="{{ $s->id }}" @selected((int) old($key.'supplier_id', $plannedId) === $s->id)>{{ $s->name }} ({{ $s->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="g{{ $plannedId }}-confirmation">{{ __('Confirmation date') }}</label>
                    <input id="g{{ $plannedId }}-confirmation" type="date" name="{{ $name('confirmation_date') }}" class="form-control" value="{{ old($key.'confirmation_date', now()->toDateString()) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="g{{ $plannedId }}-delivery">{{ __('Delivery date') }}</label>
                    <input id="g{{ $plannedId }}-delivery" type="date" name="{{ $name('delivery_date') }}" class="form-control" value="{{ old($key.'delivery_date') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="g{{ $plannedId }}-currency">{{ __('Currency') }}</label>
                    <select id="g{{ $plannedId }}-currency" name="{{ $name('currency') }}" class="form-select">
                        @foreach($currencies as $code => $label)
                            <option value="{{ $code }}" @selected(old($key.'currency', $currency) === $code)>{{ $code }} — {{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="g{{ $plannedId }}-deposit">{{ __('Deposit %') }}</label>
                    <input id="g{{ $plannedId }}-deposit" type="number" step="0.01" min="0" max="100" name="{{ $name('deposit_percent') }}" class="form-control" value="{{ old($key.'deposit_percent', $planned?->deposit_percent) }}">
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="g{{ $plannedId }}-terms">{{ __('Payment terms') }}</label>
                    <input id="g{{ $plannedId }}-terms" name="{{ $name('payment_terms') }}" maxlength="255" class="form-control" value="{{ old($key.'payment_terms') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="g{{ $plannedId }}-merch">{{ __('Merchandiser') }}</label>
                    <select id="g{{ $plannedId }}-merch" name="{{ $name('merchandiser_id') }}" class="form-select">
                        @foreach($merchandisers as $m)
                            <option value="{{ $m->id }}" @selected((int) old($key.'merchandiser_id', auth()->id()) === $m->id)>{{ $m->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="g{{ $plannedId }}-notes">{{ __('Notes') }}</label>
                    <input id="g{{ $plannedId }}-notes" name="{{ $name('notes') }}" class="form-control" value="{{ old($key.'notes') }}">
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('Add') }}</th><th>{{ __('No.') }}</th><th></th><th>{{ __('Item') }}</th>
                        <th class="text-end">{{ __('Cartons') }}</th><th class="text-end">{{ __('Qty / carton') }}</th><th class="text-end">{{ __('Total qty') }}</th>
                        <th style="min-inline-size:9rem">{{ __("Supplier's unit price") }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($lines as $line)
                        @php $lineKey = $key."lines.{$line->id}."; $lineName = "groups[{$plannedId}][lines][{$line->id}]"; @endphp
                        <tr>
                            <td><input type="checkbox" class="form-check-input" name="{{ $lineName }}[include]" value="1" @checked(old($lineKey.'include', ! $errors->any()))></td>
                            <td class="app-num">{{ $line->line_no }}</td>
                            <td>@include('supplier-orders._photo', ['product' => $line->product])</td>
                            <td>
                                <div class="fw-semibold">{{ $line->product?->name }}</div>
                                <div class="text-muted small" style="white-space:normal">{{ $line->description }}</div>
                            </td>
                            <td class="app-num text-end">{{ \App\Services\SupplierOrders\Quantity::trim($line->cartons) }}</td>
                            <td class="app-num text-end">{{ \App\Services\SupplierOrders\Quantity::trim($line->qty_per_carton) }}</td>
                            <td class="app-num text-end">{{ \App\Services\SupplierOrders\Quantity::trim($line->total_qty) }} {{ $line->unit }}</td>
                            <td>
                                <input type="number" step="0.0001" min="0" name="{{ $lineName }}[unit_price]" class="form-control form-control-sm" dir="ltr"
                                       value="{{ old($lineKey.'unit_price', $line->supplier_price !== null ? \App\Services\SupplierOrders\Quantity::trim($line->supplier_price) : '') }}">
                                @if($line->supplier_currency && $line->supplier_currency !== $currency)
                                    <div class="form-text text-warning">{{ __('Planned in :currency', ['currency' => $line->supplier_currency]) }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
    <div class="d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('Create supplier orders') }}</button>
        <a href="{{ route('supplier-orders.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endif
@endsection
