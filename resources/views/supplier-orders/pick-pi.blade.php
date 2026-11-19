@extends('layouts.app')

@section('title', __('New purchase orders'))

@section('content')
<x-page-header :title="__('New purchase orders')" :back="route('supplier-orders.index')" :back-label="__('Back to the board')"
               :subtitle="__('Purchase orders are made from a sales order (PI): choose the PI, then one order is made per factory.')" />

<x-section :title="__('Sales orders waiting for purchase orders')" icon="cart-plus" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('PI') }}</th><th scope="col">{{ __('Client') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th></tr></thead>
            <tbody>
            @forelse($pis as $pi)
                <tr>
                    <td><a href="{{ route('proforma-invoices.show', $pi) }}" class="app-ltr">{{ $pi->reference() }}</a></td>
                    <td><bdi>{{ $pi->client?->name }}</bdi></td>
                    <td><x-status :tone="$pi->status === 'confirmed' ? 'done' : 'info'">{{ $pi->statusLabel() }}</x-status></td>
                    <td class="text-end"><a href="{{ route('supplier-orders.create', ['pi' => $pi->id]) }}" class="btn btn-primary btn-sm"><i class="bi bi-cart-plus" aria-hidden="true"></i>{{ __('Make purchase orders') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-0"><x-empty icon="check2-all" :title="__('No sales order is waiting for purchase orders.')" :text="\App\Services\SupplierOrders\SupplierOrderService::notOrderableMessage()" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
