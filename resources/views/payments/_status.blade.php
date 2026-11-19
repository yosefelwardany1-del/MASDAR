{{-- A payment's status pill. $status from PaymentQueries::status(); $effective (optional) for a reversal. --}}
@php
    $pill = [
        'awaiting' => ['app-pill--due', 'bi-hourglass-split', __('Awaiting approval')],
        'approved' => ['app-pill--done', 'bi-check2-circle', __('Approved')],
        'effective' => ['app-pill--done', 'bi-check2', __('Effective')],
        'reversed' => ['app-pill--skip', 'bi-x-circle', __('Reversed')],
        'reversal' => ['app-pill--late', 'bi-arrow-counterclockwise', __('Reversal')],
        'withdrawn' => ['app-pill--skip', 'bi-slash-circle', __('Withdrawn')],
        'withdrawal' => ['app-pill--skip', 'bi-arrow-counterclockwise', __('Withdrawal')],
    ][$status] ?? ['', 'bi-question', $status];
@endphp
<span class="app-pill {{ $pill[0] }}"><i class="bi {{ $pill[1] }}" aria-hidden="true"></i>{{ $pill[2] }}</span>
