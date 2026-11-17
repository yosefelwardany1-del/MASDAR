{{-- A supplier order's status as a pill: an icon and a word. Requires $status. --}}
@php
    [$tone, $icon] = ['open' => ['info', 'play-circle'], 'completed' => ['done', 'check-circle'], 'cancelled' => ['skip', 'x-circle']][$status] ?? ['neutral', 'circle'];
@endphp
<x-status :tone="$tone" :icon="$icon">{{ \App\Services\SupplierOrders\SupplierOrderService::statusLabel($status) }}</x-status>
