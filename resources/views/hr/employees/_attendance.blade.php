@php use App\Models\AttendanceRecord; @endphp
<h2 class="visually-hidden" id="attendance-head">{{ __('Attendance') }}</h2>
<x-section :title="__('This month')" icon="clock-history" :subtitle="__('Nothing recorded on a working day means present.')" flush>
    @can('leave.view')
        <x-slot:actions><a href="{{ route('attendance.index') }}" class="small fw-medium">{{ __('Attendance sheet') }}</a></x-slot:actions>
    @endcan
    @if($marks->isEmpty())
        <div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ trans_choice('{0} No working day yet this month.|{1} Present on the one working day so far.|[2,*] Present on all :count working days so far.', $workingDaysThisMonth, ['count' => $workingDaysThisMonth]) }}</div>
    @else
        <ul class="app-list">
            @foreach($marks as $date => $mark)
                <li class="app-list__row">
                    <div class="app-list__main">
                        <span class="app-list__title"><span class="app-num">{{ $date }}</span></span>
                        @if($mark->note)<div class="app-list__sub" dir="auto">{{ $mark->note }}</div>@endif
                    </div>
                    @if((float) $mark->overtime_hours > 0)<x-status tone="info" icon="plus-circle">{{ __('Overtime') }} <span class="app-num">{{ rtrim(rtrim((string) $mark->overtime_hours, '0'), '.') }}</span> {{ __('h') }}</x-status>@endif
                    <x-status :tone="in_array($mark->status, ['absent'], true) ? 'late' : (in_array($mark->status, ['half_day', 'late'], true) ? 'due' : 'done')">{{ AttendanceRecord::statusLabel($mark->status) }}</x-status>
                </li>
            @endforeach
        </ul>
    @endif
</x-section>
