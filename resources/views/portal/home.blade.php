@extends('layouts.portal')

@section('title', __('My orders'))

@php use App\Services\ProformaInvoices\PiAmounts; use App\Models\ProformaInvoice; @endphp

@section('content')
<x-page-header :title="$party->name" :subtitle="__('Your orders with us, what is paid and due, and where your goods are.')" />

<x-section :title="__('Orders')" icon="receipt" flush class="mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Proforma invoice') }}</th><th scope="col">{{ __('Your PO') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col" class="text-end">{{ __('Total') }}</th><th scope="col" class="text-end">{{ __('Paid') }}</th><th scope="col" class="text-end">{{ __('Balance due') }}</th></tr></thead>
            <tbody>
            @forelse($orders as $o)
                <tr>
                    <td><a href="{{ route('portal.pi', $o['pi']) }}" target="_blank" class="fw-semibold app-ltr">{{ $o['pi']->reference() }}</a></td>
                    <td><span class="app-ltr">{{ $o['pi']->po_number ?? '—' }}</span></td>
                    <td>{{ $o['pi']->statusLabel() }}</td>
                    <td class="text-end"><span class="app-num">{{ PiAmounts::money($o['total'], $o['pi']->currency) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ PiAmounts::money($o['paid'], $o['pi']->currency) }}</span></td>
                    <td class="text-end"><span class="app-num fw-semibold">{{ PiAmounts::money($o['due'], $o['pi']->currency) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-0"><x-empty icon="receipt" :title="__('No orders yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>

<x-section :title="__('Your containers')" icon="boxes" flush class="mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Container') }}</th><th scope="col">{{ __('Vessel') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('ETD') }}</th><th scope="col">{{ __('ETA') }}</th></tr></thead>
            <tbody>
            @forelse($containers as $c)
                <tr>
                    <td><a href="{{ route('portal.container', $c) }}" class="fw-semibold app-ltr">{{ $c->container_no ?: __('Not yet known') }}</a></td>
                    <td>{{ $c->shipment?->vessel ?? '—' }}</td>
                    <td>{{ $c->latestEvent ? ($statuses[$c->latestEvent->status] ?? $c->latestEvent->status) : __('Booked') }}</td>
                    <td><span class="app-num">{{ $c->shipment?->etd?->format('Y-m-d') ?? '—' }}</span></td>
                    <td><span class="app-num">{{ $c->shipment?->eta?->format('Y-m-d') ?? '—' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><x-empty icon="boxes" :title="__('Nothing shipped yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>

<x-section :title="__('Payments received')" icon="cash-coin" flush>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Reference') }}</th><th scope="col">{{ __('Proforma invoice') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th></tr></thead>
            <tbody>
            @forelse($payments as $p)
                <tr><td><span class="app-num">{{ $p->paid_on->format('Y-m-d') }}</span></td><td><span class="app-ltr">{{ $p->reference }}</span></td><td><span class="app-ltr">{{ $p->proformaInvoice?->reference() }}</span></td>
                    <td class="text-end"><span class="app-num">{{ PiAmounts::money((string) $p->signed_amount, $p->currency) }}</span></td></tr>
            @empty
                <tr><td colspan="4" class="p-0"><div class="app-panel-empty">{{ __('No payment yet.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
