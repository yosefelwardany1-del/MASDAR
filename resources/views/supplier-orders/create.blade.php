@extends('layouts.app')

@section('title', __('New supplier orders'))

@use('App\Services\SupplierOrders\Quantity')

@section('content')
<x-page-header :title="__('New supplier orders from :pi', ['pi' => $pi->reference()])"
               :subtitle="__('One supplier order is made per supplier. Lines are grouped by the supplier planned on the PI; pick another supplier for a group if needed.')"
               :back="route('supplier-orders.index')" :back-label="__('Back to the board')">
    <x-slot:meta>
        <span class="app-pill"><i class="bi bi-person" aria-hidden="true"></i>{{ __('Client') }}: <bdi>{{ $pi->client?->name }}</bdi></span>
    </x-slot:meta>
</x-page-header>

@include('supplier-orders._form-errors')

@if($groups->isEmpty())
    <div class="app-card">
        <x-empty icon="check2-all" :title="__('Every line of this PI is already on a supplier order.')">
            <x-slot:action><a href="{{ route('supplier-orders.index') }}" class="btn btn-light btn-sm">{{ __('Back to the board') }}</a></x-slot:action>
        </x-empty>
    </div>
@else
<form method="POST" action="{{ route('supplier-orders.store') }}" class="app-fu-form">
    @csrf
    <input type="hidden" name="pi" value="{{ $pi->id }}">
    @foreach($groups as $plannedId => $lines)
        @php
            $planned = $lines->first()->supplier;
            $key = "groups.{$plannedId}.";
            $name = fn (string $field) => "groups[{$plannedId}][{$field}]";
            $lineCurrencies = $lines->pluck('supplier_currency')->filter()->unique();
            $currency = $lineCurrencies->count() === 1 ? $lineCurrencies->first() : $defaultCurrency;
            $err = fn (string $field) => $errors->has($key.$field) ? 'is-invalid' : '';
        @endphp
        <x-section :title="$planned ? __('Planned supplier: :name', ['name' => $planned->name]) : __('No supplier planned on the PI')" icon="buildings"
                   :subtitle="trans_choice('{1} :count line|[2,*] :count lines', $lines->count(), ['count' => $lines->count()])" flush>
            <div class="app-section__body border-bottom">
                <div class="row g-3">
                    <div class="col-md-6 col-xl-4">
                        <label class="form-label" for="g{{ $plannedId }}-supplier">{{ __('Supplier') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                        <select id="g{{ $plannedId }}-supplier" name="{{ $name('supplier_id') }}" class="form-select {{ $err('supplier_id') }}">
                            <option value="">{{ __('Pick a supplier') }}</option>
                            @foreach($suppliers as $s)
                                <option value="{{ $s->id }}" @selected((int) old($key.'supplier_id', $plannedId) === $s->id)>{{ $s->name }} ({{ $s->code }})</option>
                            @endforeach
                        </select>
                        @error($key.'supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="form-label" for="g{{ $plannedId }}-confirmation">{{ __('Confirmation date') }}</label>
                        <input id="g{{ $plannedId }}-confirmation" type="date" name="{{ $name('confirmation_date') }}" class="form-control {{ $err('confirmation_date') }}" value="{{ old($key.'confirmation_date', now()->toDateString()) }}">
                        @error($key.'confirmation_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="form-label" for="g{{ $plannedId }}-delivery">{{ __('Delivery date') }}</label>
                        <input id="g{{ $plannedId }}-delivery" type="date" name="{{ $name('delivery_date') }}" class="form-control {{ $err('delivery_date') }}" value="{{ old($key.'delivery_date') }}" aria-describedby="g{{ $plannedId }}-delivery-help">
                        <div class="form-text" id="g{{ $plannedId }}-delivery-help">{{ __('Step due dates count back from it.') }}</div>
                        @error($key.'delivery_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="form-label" for="g{{ $plannedId }}-currency">{{ __('Currency') }}</label>
                        <select id="g{{ $plannedId }}-currency" name="{{ $name('currency') }}" class="form-select {{ $err('currency') }}">
                            @foreach($currencies as $code => $label)
                                <option value="{{ $code }}" @selected(old($key.'currency', $currency) === $code)>{{ $code }} — {{ $label }}</option>
                            @endforeach
                        </select>
                        @error($key.'currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="form-label" for="g{{ $plannedId }}-deposit">{{ __('Deposit %') }}</label>
                        <input id="g{{ $plannedId }}-deposit" type="number" step="0.01" min="0" max="100" name="{{ $name('deposit_percent') }}" class="form-control {{ $err('deposit_percent') }}" dir="ltr" value="{{ old($key.'deposit_percent', $planned?->deposit_percent) }}">
                        @error($key.'deposit_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 col-xl-5">
                        <label class="form-label" for="g{{ $plannedId }}-terms">{{ __('Payment terms') }}</label>
                        <input id="g{{ $plannedId }}-terms" name="{{ $name('payment_terms') }}" maxlength="255" class="form-control {{ $err('payment_terms') }}" value="{{ old($key.'payment_terms') }}">
                        @error($key.'payment_terms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <label class="form-label" for="g{{ $plannedId }}-merch">{{ __('Merchandiser') }}</label>
                        <select id="g{{ $plannedId }}-merch" name="{{ $name('merchandiser_id') }}" class="form-select {{ $err('merchandiser_id') }}">
                            @foreach($merchandisers as $m)
                                <option value="{{ $m->id }}" @selected((int) old($key.'merchandiser_id', auth()->id()) === $m->id)>{{ $m->name }}</option>
                            @endforeach
                        </select>
                        @error($key.'merchandiser_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-xl-4">
                        <label class="form-label" for="g{{ $plannedId }}-notes">{{ __('Notes') }}</label>
                        <input id="g{{ $plannedId }}-notes" name="{{ $name('notes') }}" class="form-control {{ $err('notes') }}" value="{{ old($key.'notes') }}">
                        @error($key.'notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('Add') }}</th><th>{{ __('No.') }}</th><th><span class="visually-hidden">{{ __('Photo') }}</span></th><th>{{ __('Item') }}</th>
                        <th class="text-end">{{ __('Cartons') }}</th><th class="text-end">{{ __('Qty / carton') }}</th><th class="text-end">{{ __('Total qty') }}</th>
                        <th style="min-inline-size:9rem">{{ __("Supplier's unit price") }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($lines as $line)
                        @php $lineKey = $key."lines.{$line->id}."; $lineName = "groups[{$plannedId}][lines][{$line->id}]"; @endphp
                        <tr>
                            <td><input type="checkbox" class="form-check-input" id="l{{ $line->id }}-include" name="{{ $lineName }}[include]" value="1" @checked(old($lineKey.'include', ! $errors->any()))>
                                <label class="visually-hidden" for="l{{ $line->id }}-include">{{ __('Add') }} {{ $line->line_no }}</label></td>
                            <td class="app-num">{{ $line->line_no }}</td>
                            <td>@include('supplier-orders._photo', ['product' => $line->product])</td>
                            <td>
                                <div class="fw-semibold">{{ $line->product?->name }}</div>
                                <div class="app-fu-sub" style="white-space:normal">{{ $line->description }}</div>
                            </td>
                            <td class="app-num text-end">{{ Quantity::trim($line->cartons) }}</td>
                            <td class="app-num text-end">{{ Quantity::trim($line->qty_per_carton) }}</td>
                            <td class="app-num text-end">{{ Quantity::trim($line->total_qty) }} {{ $line->unit }}</td>
                            <td>
                                <input type="number" step="0.0001" min="0" name="{{ $lineName }}[unit_price]" style="max-inline-size:12rem" class="form-control form-control-sm {{ $errors->has($lineKey.'unit_price') ? 'is-invalid' : '' }}" dir="ltr"
                                       aria-label="{{ __("Supplier's unit price") }} {{ $line->line_no }}"
                                       value="{{ old($lineKey.'unit_price', $line->supplier_price !== null ? Quantity::trim($line->supplier_price) : '') }}">
                                @error($lineKey.'unit_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                @if($line->supplier_currency && $line->supplier_currency !== $currency)
                                    <div class="form-text text-warning"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> {{ __('Planned in :currency', ['currency' => $line->supplier_currency]) }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-section>
    @endforeach
    <div class="app-fu-form__actions">
        <button class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Create supplier orders') }}</button>
        <a href="{{ route('supplier-orders.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endif
@endsection

@if($errors->any())
    @push('scripts')
    <script>document.addEventListener('DOMContentLoaded', () => document.getElementById('form-errors')?.focus());</script>
    @endpush
@endif
