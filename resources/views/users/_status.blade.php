@php
    $tone = ['active' => 'success', 'inactive' => 'secondary', 'pending' => 'warning'][$status] ?? 'secondary';
    $label = ['active' => __('Active'), 'inactive' => __('Inactive'), 'pending' => __('Awaiting approval')][$status] ?? $status;
@endphp
<span class="badge bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis">{{ $label }}</span>
