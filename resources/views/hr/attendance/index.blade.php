@extends('layouts.app')

@section('title', __('Attendance sheet'))

@php
    use App\Models\AttendanceRecord;
    $rtl = \App\Http\Middleware\SetUserLocale::isRtl();
    $editable = Gate::allows('leave.edit');
    $today = now()->toDateString();
    // A mark's look: icon and tone; nothing recorded on a working day means present.
    $look = [
        'absent' => ['x-lg', 'late'], 'half_day' => ['circle-half', 'due'], 'late' => ['clock', 'due'],
        'remote' => ['house', 'info'], 'present' => ['check2', 'done'],
    ];
    $prev = $month->subMonthNoOverflow()->format('Y-m');
    $next = $month->addMonthNoOverflow()->format('Y-m');
@endphp

@section('content')
<x-page-header :title="__('Leave & attendance')" :subtitle="__('Requests and their decisions, what each employee has left, and the month\'s attendance.')" />
@include('hr._leave-nav', ['active' => 'attendance'])

@unless($hasHolidays)
    <div class="alert alert-warning d-flex gap-2 align-items-start" role="status">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>{{ __('No public holiday is entered for :year, so every weekday counts as a working day. Add the year\'s holidays and make-up working days below.', ['year' => $month->year]) }}</div>
    </div>
@endunless

<x-section :title="\App\Services\Hr\HrCalendar::monthLabel($month)" icon="grid-3x3"
           :subtitle="trans_choice('{1} one working day|[2,*] :count working days', $workingDays, ['count' => $workingDays])" flush class="mb-4">
    <x-slot:actions>
        <a href="{{ route('attendance.index', ['month' => $prev]) }}" class="btn btn-sm btn-light" aria-label="{{ __('Previous month') }}"><i class="bi bi-chevron-{{ $rtl ? 'right' : 'left' }}" aria-hidden="true"></i></a>
        <form method="GET" class="d-inline"><label class="visually-hidden" for="att-month">{{ __('Month') }}</label><input type="month" name="month" id="att-month" value="{{ $month->format('Y-m') }}" class="form-control form-control-sm" onchange="this.form.submit()"></form>
        <a href="{{ route('attendance.index', ['month' => $next]) }}" class="btn btn-sm btn-light" aria-label="{{ __('Next month') }}"><i class="bi bi-chevron-{{ $rtl ? 'left' : 'right' }}" aria-hidden="true"></i></a>
    </x-slot:actions>

    <ul class="app-hr-legend" aria-label="{{ __('Key:') }}">
        <li><span class="app-hr-cell is-present" aria-hidden="true"></span>{{ __('Present') }}</li>
        @foreach(['absent', 'half_day', 'late', 'remote'] as $s)
            <li><span class="app-hr-cell is-{{ $look[$s][1] }}" aria-hidden="true"><i class="bi bi-{{ $look[$s][0] }}"></i></span>{{ AttendanceRecord::statusLabel($s) }}</li>
        @endforeach
        <li><span class="app-hr-cell is-leave" aria-hidden="true"><i class="bi bi-calendar-x"></i></span>{{ __('On leave') }}</li>
        <li><span class="app-hr-cell is-off" aria-hidden="true"></span>{{ __('Weekend or holiday') }}</li>
        @if($editable)<li class="text-muted">{{ __('Select a day to record it.') }}</li>@endif
    </ul>

    @if($employees->isEmpty())
        <div class="app-panel-empty">{{ __('No employees on the staff this month.') }}</div>
    @else
        <div class="table-responsive app-hr-sheetwrap">
            <table class="table table-sm mb-0 app-hr-sheet">
                <thead>
                    <tr>
                        <th scope="col" class="app-hr-sheet__name">{{ __('Employee') }}</th>
                        @foreach($days as $date => $d)
                            <th scope="col" class="{{ $d['working'] ? '' : 'is-off' }} {{ $date === $today ? 'is-today' : '' }}" title="{{ $d['holiday']?->name }}">
                                <span class="app-hr-sheet__dow">{{ $d['date']->locale(app()->getLocale())->isoFormat('dd') }}</span>
                                <span class="app-num">{{ $d['date']->day }}</span>
                            </th>
                        @endforeach
                        <th scope="col" class="text-end">{{ __('Absent') }}</th>
                        <th scope="col" class="text-end">{{ __('Overtime') }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($employees as $e)
                    @php $absent = '0'; $overtime = '0'; @endphp
                    <tr>
                        <th scope="row" class="app-hr-sheet__name"><a href="{{ route('employees.show', $e) }}#attendance">{{ $e->name }}</a></th>
                        @foreach($days as $date => $d)
                            @php
                                $onStaff = $e->isOnStaff($date);
                                $mark = $marks[$e->id][$date] ?? null;
                                $leaveType = $leave[$e->id][$date] ?? null;
                                if ($mark && $d['working']) { $absent = bcadd($absent, AttendanceRecord::unpaidDays($mark->status), 2); }
                                if ($mark) { $overtime = bcadd($overtime, (string) $mark->overtime_hours, 2); }
                                $class = ! $onStaff ? 'is-out' : ($leaveType ? 'is-leave' : ($mark ? 'is-'.$look[$mark->status][1] : ($d['working'] ? ($date <= $today ? 'is-present' : '') : 'is-off')));
                                $label = ! $onStaff ? __('Not on the staff') : ($leaveType ? ($leaveTypes[$leaveType]['label'] ?? $leaveType) : ($mark ? AttendanceRecord::statusLabel($mark->status) : ($d['working'] ? __('Present') : ($d['holiday']?->name ?? __('Weekend')))));
                                $tip = $date.' · '.$label.($mark && (float) $mark->overtime_hours > 0 ? ' · +'.rtrim(rtrim((string) $mark->overtime_hours, '0'), '.').' '.__('h') : '').($mark?->note ? ' · '.$mark->note : '');
                            @endphp
                            <td class="app-hr-sheet__day">
                                @if($editable && $onStaff && $date <= $today)
                                    <button type="button" class="app-hr-cell {{ $class }}" title="{{ $tip }}" data-record data-employee="{{ $e->id }}" data-date="{{ $date }}" data-status="{{ $mark?->status ?? 'present' }}" data-overtime="{{ $mark ? rtrim(rtrim((string) $mark->overtime_hours, '0'), '.') : '' }}">
                                @else
                                    <span class="app-hr-cell {{ $class }}" title="{{ $tip }}">
                                @endif
                                    @if($leaveType)<i class="bi bi-calendar-x" aria-hidden="true"></i>@elseif($mark && $mark->status !== 'present')<i class="bi bi-{{ $look[$mark->status][0] }}" aria-hidden="true"></i>@endif
                                    @if($mark && (float) $mark->overtime_hours > 0)<sup class="app-hr-cell__ot">+</sup>@endif
                                    <span class="visually-hidden">{{ $tip }}</span>
                                @if($editable && $onStaff && $date <= $today)</button>@else</span>@endif
                            </td>
                        @endforeach
                        <td class="text-end"><span class="app-num">{{ bccomp($absent, '0', 2) ? rtrim(rtrim($absent, '0'), '.') : '—' }}</span></td>
                        <td class="text-end"><span class="app-num">{{ bccomp($overtime, '0', 2) ? rtrim(rtrim($overtime, '0'), '.').' '.__('h') : '—' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-section>

<div class="row g-3">
    @if($editable)
        <div class="col-xl-5">
            <x-section :title="__('Record a day')" icon="pencil-square" id="record">
                <form method="POST" action="{{ route('attendance.store') }}" class="app-form" data-record-form>
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label" for="att-employee">{{ __('Employee') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <select name="employee_id" id="att-employee" class="form-select @error('employee_id') is-invalid @enderror" required>
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach($employees as $e)<option value="{{ $e->id }}" @selected((int) old('employee_id') === $e->id)>{{ $e->name }}</option>@endforeach
                            </select>
                            @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="att-date">{{ __('Day') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="date" name="work_date" id="att-date" class="form-control @error('work_date') is-invalid @enderror" value="{{ old('work_date', $today) }}" max="{{ $today }}" required>
                            @error('work_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="att-status">{{ __('How the day went') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <select name="status" id="att-status" class="form-select @error('status') is-invalid @enderror" required>
                                @foreach(AttendanceRecord::STATUSES as $s)<option value="{{ $s }}" @selected(old('status', 'absent') === $s)>{{ AttendanceRecord::statusLabel($s) }}</option>@endforeach
                            </select>
                            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="att-ot">{{ __('Overtime hours') }}</label>
                            <input name="overtime_hours" id="att-ot" inputmode="decimal" dir="ltr" class="form-control app-num @error('overtime_hours') is-invalid @enderror" value="{{ old('overtime_hours') }}">
                            @error('overtime_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="att-note">{{ __('Note') }}</label>
                            <input name="note" id="att-note" dir="auto" class="form-control" value="{{ old('note') }}">
                        </div>
                        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button></div>
                    </div>
                </form>
                <p class="app-note mt-3 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('A day recorded again replaces what was there; every record is kept. Absences and half days are deducted in payroll; overtime is paid.') }}</span></p>
            </x-section>
        </div>
    @endif
    <div class="{{ $editable ? 'col-xl-7' : 'col-12' }}">
        <x-section :title="__('Public holidays :year', ['year' => $month->year])" icon="calendar-event" id="holidays" flush>
            @if($holidays->isEmpty())
                <div class="app-panel-empty"><i class="bi bi-calendar" aria-hidden="true"></i>{{ __('None entered yet.') }}</div>
            @else
                <ul class="app-list">
                    @foreach($holidays as $h)
                        <li class="app-list__row">
                            <div class="app-list__main">
                                <span class="app-list__title"><span class="app-num">{{ $h->holiday_on->format('Y-m-d') }}</span> · <span dir="auto">{{ $h->name }}</span></span>
                                <div class="app-list__sub">{{ $h->holiday_on->locale(app()->getLocale())->isoFormat('dddd') }}</div>
                            </div>
                            @if($h->is_workday)<x-status tone="info" icon="briefcase">{{ __('Make-up working day') }}</x-status>@else<x-status tone="skip" icon="sun">{{ __('Day off') }}</x-status>@endif
                            @if($editable)
                                <form method="POST" action="{{ route('attendance.holidays.destroy', $h) }}" onsubmit="return confirm(@js(__('Remove this day from the list?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-light" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}: {{ $h->name }}"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($editable)
                <form method="POST" action="{{ route('attendance.holidays.store') }}" class="app-hr-inlineform border-top">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small" for="hol-date">{{ __('Date') }}</label>
                            <input type="date" name="holiday_on" id="hol-date" class="form-control @error('holiday_on') is-invalid @enderror" required>
                            @error('holiday_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="hol-name">{{ __('Name') }}</label>
                            <input name="name" id="hol-name" dir="auto" class="form-control" placeholder="{{ __('e.g. National Day') }}" required>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="hidden" name="is_workday" value="0">
                                <input class="form-check-input" type="checkbox" name="is_workday" value="1" id="hol-work">
                                <label class="form-check-label small" for="hol-work">{{ __('A make-up working day') }}</label>
                            </div>
                        </div>
                        <div class="col-md-2 d-grid"><button class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add') }}</button></div>
                    </div>
                    <div class="form-text">{{ __('China announces each year\'s holidays and the weekend days worked instead; enter both.') }}</div>
                </form>
            @endif
        </x-section>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // A day of the sheet fills the form: the employee, the date and what is recorded now.
    (() => {
        const form = document.querySelector('[data-record-form]');
        if (! form) return;
        document.querySelectorAll('[data-record]').forEach((cell) => cell.addEventListener('click', () => {
            form.employee_id.value = cell.dataset.employee;
            form.work_date.value = cell.dataset.date;
            form.status.value = cell.dataset.status === 'present' ? 'absent' : cell.dataset.status;
            form.overtime_hours.value = cell.dataset.overtime || '';
            form.scrollIntoView({ block: 'center', behavior: 'smooth' });
            form.status.focus({ preventScroll: true });
        }));
    })();
</script>
@endpush
