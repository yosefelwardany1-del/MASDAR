{{-- A supplier order's status as a badge. Requires $status. --}}
@php
    $tone = ['open' => 'primary', 'completed' => 'success', 'cancelled' => 'secondary'][$status] ?? 'secondary';
@endphp
<span class="badge bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis">{{ \App\Services\SupplierOrders\SupplierOrderService::statusLabel($status) }}</span>
