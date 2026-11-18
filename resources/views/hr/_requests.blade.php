{{-- Leave requests with their status and, where allowed, the decision buttons.
     $requests (with ->status), $showEmployee, $selfService (the caller's own "My leave"). --}}
@php use App\Models\LeaveRequest; use App\Models\Lookup; $me = auth()->user(); $today = now()->toDateString(); @endphp
@if($requests->isEmpty())
    <div class="app-panel-empty"><i class="bi bi-calendar" aria-hidden="true"></i>{{ __('No leave requests.') }}</div>
@else
    <ul class="app-list app-hr-requests">
        @foreach($requests as $r)
            @php
                $status = $r->status ?? $r->status();
                $own = $r->employee?->user_id === $me->id;
                $mayDecide = $status === 'pending' && ! $own && $me->can('leave.approve') && ! $selfService;
                $mayCancel = in_array($status, ['pending', 'approved'], true)
                    && ($selfService ? ($status === 'pending' || $r->starts_on->toDateString() > $today) : ($me->can('leave.edit') && ($status === 'pending' || $r->ends_on->toDateString() >= $today)));
                $days = rtrim(rtrim((string) $r->days, '0'), '.');
            @endphp
            <li class="app-list__row app-hr-request" id="leave-{{ $r->id }}">
                <div class="app-list__main">
                    <span class="app-list__title">
                        @if($showEmployee)<a href="{{ route('employees.show', $r->employee_id) }}#leave">{{ $r->employee?->name }}</a> · @endif{{ Lookup::label('leave_type', $r->leave_type) }}
                    </span>
                    <div class="app-list__sub">
                        <span class="app-num">{{ $r->starts_on->format('Y-m-d') }}</span>@if($r->ends_on->ne($r->starts_on)) → <span class="app-num">{{ $r->ends_on->format('Y-m-d') }}</span>@endif
                        · {{ trans_choice('{0} :days days|{1} :days day|[2,*] :days days', (int) ceil((float) $r->days), ['days' => $days]) }}
                        @if($r->reason) · <span dir="auto">{{ $r->reason }}</span>@endif
                    </div>
                    @if($r->latestDecision)
                        <div class="app-list__sub small">{{ LeaveRequest::statusLabel($status) }} · <bdi>{{ $r->latestDecision->decider?->name }}</bdi> · <span class="app-num">{{ $r->latestDecision->created_at->format('Y-m-d') }}</span>@if($r->latestDecision->note) · <span dir="auto">{{ $r->latestDecision->note }}</span>@endif</div>
                    @endif
                </div>
                <div class="app-hr-request__side">
                    <x-status :tone="LeaveRequest::statusTone($status)">{{ LeaveRequest::statusLabel($status) }}</x-status>
                    @if($mayDecide || $mayCancel)
                        <div class="app-hr-request__actions">
                            @if($mayDecide)
                                <form method="POST" action="{{ route('leave.approve', $r) }}">@csrf<button class="btn btn-sm btn-success"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Approve') }}</button></form>
                                <form method="POST" action="{{ route('leave.reject', $r) }}" onsubmit="this.note.value = prompt(@js(__('Why not? (optional)'))) ?? ''; return true;">@csrf<input type="hidden" name="note"><button class="btn btn-sm btn-outline-danger">{{ __('Reject') }}</button></form>
                            @endif
                            @if($mayCancel)
                                <form method="POST" action="{{ $selfService ? route('my-leave.cancel', $r) : route('leave.cancel', $r) }}" onsubmit="return confirm(@js(__('Cancel this leave request?')));">@csrf<button class="btn btn-sm btn-light">{{ __('Cancel request') }}</button></form>
                            @endif
                        </div>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
