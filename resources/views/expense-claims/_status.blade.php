{{-- A claim's status chip; "paid back" once a payment that counts is against it. $claim, $paidCount (optional). --}}
@php $st = ($paidCount ?? 0) > 0 ? 'paid' : $claim->status(); @endphp
<x-status :tone="\App\Models\ExpenseClaim::statusTone($st)">{{ \App\Models\ExpenseClaim::statusLabel($st) }}</x-status>
