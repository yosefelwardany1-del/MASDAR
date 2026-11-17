@extends('layouts.app')

@section('title', __('Add lines to :number', ['number' => $order->number]))

@use('App\Services\SupplierOrders\Quantity')

@section('content')
<x-page-header :title="__('Add lines to :number', ['number' => $order->number])"
               :back="route('supplier-orders.show', $order)" :back-label="__('Back to the order')">
    <x-slot:meta>
        <span class="app-pill"><i class="bi bi-receipt" aria-hidden="true"></i>{{ __('From :pi', ['pi' => $pi->reference()]) }}</span>
        <span class="app-pill"><i class="bi bi-person" aria-hidden="true"></i><bdi>{{ $pi->client?->name }}</bdi></span>
        <span class="app-pill"><i class="bi bi-buildings" aria-hidden="true"></i>{{ __('Supplier') }}: <bdi>{{ $seeSuppliers ? $order->supplier?->name : '—' }}</bdi></span>
        <span class="app-pill"><i class="bi bi-currency-exchange" aria-hidden="true"></i><span class="app-ltr">{{ $order->currency }}</span></span>
    </x-slot:meta>
</x-page-header>

@include('supplier-orders._form-errors')

@if($lines->isEmpty())
    <div class="app-card">
        <x-empty icon="check2-all" :title="__('Every line of this PI is already on a supplier order.')">
            <x-slot:action><a href="{{ route('supplier-orders.show', $order) }}" class="btn btn-light btn-sm">{{ __('Back to the order') }}</a></x-slot:action>
        </x-empty>
    </div>
@else
<form method="POST" action="{{ route('supplier-orders.lines.store', $order) }}">
    @csrf
    <input type="hidden" name="pi" value="{{ $pi->id }}">
    <x-section :title="__('Lines')" icon="list-ul" flush :subtitle="__('Lines planned for this supplier are ticked.')">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Add') }}</th><th>{{ __('No.') }}</th><th><span class="visually-hidden">{{ __('Photo') }}</span></th><th>{{ __('Item') }}</th><th>{{ __('Planned supplier') }}</th>
                    <th class="text-end">{{ __('Cartons') }}</th><th class="text-end">{{ __('Total qty') }}</th>
                    <th style="min-inline-size:9rem">{{ __("Supplier's unit price") }}</th>
                </tr></thead>
                <tbody>
                @foreach($lines as $line)
                    @php $planned = (int) $line->supplier_id === (int) $order->supplier_id; @endphp
                    <tr>
                        <td><input type="checkbox" class="form-check-input" id="al{{ $line->id }}-include" name="lines[{{ $line->id }}][include]" value="1" @checked(old("lines.{$line->id}.include", $planned && ! $errors->any()))>
                            <label class="visually-hidden" for="al{{ $line->id }}-include">{{ __('Add') }} {{ $line->line_no }}</label></td>
                        <td class="app-num">{{ $line->line_no }}</td>
                        <td>@include('supplier-orders._photo', ['product' => $line->product])</td>
                        <td style="white-space:normal">
                            <div class="fw-semibold">{{ $line->product?->name }}</div>
                            <div class="app-fu-sub">{{ $line->description }}</div>
                        </td>
                        <td>
                            {{ $seeSuppliers ? ($line->supplier?->name ?? '—') : '—' }}
                            @if($planned)<div><x-status tone="done">{{ __('This supplier') }}</x-status></div>@endif
                        </td>
                        <td class="app-num text-end">{{ Quantity::trim($line->cartons) }}</td>
                        <td class="app-num text-end">{{ Quantity::trim($line->total_qty) }} {{ $line->unit }}</td>
                        <td>
                            <input type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][unit_price]" style="max-inline-size:12rem" class="form-control form-control-sm {{ $errors->has("lines.{$line->id}.unit_price") ? 'is-invalid' : '' }}" dir="ltr"
                                   aria-label="{{ __("Supplier's unit price") }} {{ $line->line_no }}"
                                   value="{{ old("lines.{$line->id}.unit_price", $line->supplier_price !== null ? Quantity::trim($line->supplier_price) : '') }}">
                            @error("lines.{$line->id}.unit_price")<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if($line->supplier_currency && $line->supplier_currency !== $order->currency)
                                <div class="form-text text-warning"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> {{ __('Planned in :currency', ['currency' => $line->supplier_currency]) }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-section>
    <div class="app-fu-form__actions">
        <button class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add lines') }}</button>
        <a href="{{ route('supplier-orders.show', $order) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endif
@endsection
