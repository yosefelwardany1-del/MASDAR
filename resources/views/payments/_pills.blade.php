{{-- A payment's status pill, plus "awaiting approval" on a reversal of money out still to be
     approved. Needs $payment, $queries (PaymentQueries) and $approvalRequired. --}}
@include('payments._status', ['status' => $queries->status($payment, $approvalRequired)])
@if($payment->isReversal() && $queries->awaitsApproval($payment, $approvalRequired))
    @include('payments._status', ['status' => 'awaiting'])
@endif
