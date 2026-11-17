@php
    [$tone, $icon] = ['active' => ['done', 'check-circle'], 'inactive' => ['skip', 'slash-circle'], 'pending' => ['due', 'hourglass-split']][$status] ?? ['neutral', 'circle'];
    $label = ['active' => __('Active'), 'inactive' => __('Inactive'), 'pending' => __('Awaiting approval')][$status] ?? $status;
@endphp
<x-status :tone="$tone" :icon="$icon">{{ $label }}</x-status>
