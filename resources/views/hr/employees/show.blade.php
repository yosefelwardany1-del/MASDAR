@extends('layouts.app')

@section('title', $employee->code.' · '.$employee->name)

@php
    use App\Models\Lookup;
    use Illuminate\Support\HtmlString;
    $rtl = \App\Http\Middleware\SetUserLocale::isRtl();
    $lines = fn (?string $text) => $text ? new HtmlString(nl2br(e($text))) : '—';
    $today = now()->toDateString();
    $left = $employee->left_on && $employee->left_on->toDateString() < $today;
    $words = preg_split('/\s+/u', trim($employee->name)) ?: [];
    $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    $expiringNow = $current->filter(fn ($d) => $d->expires_on && $d->daysLeft() <= $warningDays);
    $tabs = ['documents' => ['passport', __('Personal documents'), $current->count()]];
    if ($seesSalary) { $tabs['salary'] = ['cash-stack', __('Salary'), null]; }
    $tabs['leave'] = ['calendar-check', __('Leave'), $requests->where('status', 'pending')->count() ?: null];
    $tabs['attendance'] = ['clock-history', __('Attendance'), null];
    if (Gate::allows('employees.edit')) { $tabs['files'] = ['folder2-open', __('Files'), $employee->attachments->count()]; }
@endphp

@section('content')
<a href="{{ route('employees.index') }}" class="app-pagehead__back"><i class="bi {{ $rtl ? 'bi-arrow-right' : 'bi-arrow-left' }}" aria-hidden="true"></i>{{ __('Employees') }}</a>

<section class="app-card app-profile" aria-labelledby="employee-name">
    <div class="app-profile__top">
        <span class="app-profile__mark" aria-hidden="true">{{ $initials }}</span>
        <div class="app-profile__id">
            <div class="app-profile__titlerow">
                <h1 class="app-profile__name" id="employee-name"><bdi>{{ $employee->name }}</bdi></h1>
                @if($left)
                    <span class="app-pill app-pill--skip"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>{{ __('Left the company') }} <span class="app-num">{{ $employee->left_on->format('Y-m-d') }}</span></span>
                @else
                    <span class="app-pill app-pill--done"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('On the staff') }}</span>
                @endif
                @foreach($expiringNow as $doc)
                    <a href="#documents" class="app-pill {{ $doc->isExpired() ? 'app-pill--late' : 'app-pill--due' }} text-decoration-none" data-app-tab-link>
                        <i class="bi bi-passport" aria-hidden="true"></i>{{ Lookup::label('employee_document', $doc->kind) }} · {{ $doc->isExpired() ? __('Expired') : trans_choice('{0} Expires today|{1} Expires tomorrow|[2,*] Expires in :count days', $doc->daysLeft(), ['count' => $doc->daysLeft()]) }}
                    </a>
                @endforeach
            </div>
            <div class="app-profile__sub">
                <span class="app-doc-code">{{ $employee->code }}</span>
                @if($employee->name_local)<bdi class="app-profile__local">{{ $employee->name_local }}</bdi>@endif
                @if($employee->job_title)<span>{{ $employee->job_title }}</span>@endif
            </div>
        </div>
        @can('employees.edit')
            <div class="app-profile__actions">
                <a href="{{ route('employees.edit', $employee) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
            </div>
        @endcan
    </div>

    <dl class="app-details app-profile__facts">
        <div class="app-details__item"><dt>{{ __('Department') }}</dt><dd>{{ Lookup::label('department', $employee->department) ?: '—' }}</dd></div>
        <div class="app-details__item"><dt>{{ __('Work location') }}</dt><dd>{{ Lookup::label('work_location', $employee->work_location) ?: '—' }}</dd></div>
        <div class="app-details__item"><dt>{{ __('Employment type') }}</dt><dd>{{ Lookup::label('employment_type', $employee->employment_type) ?: '—' }}</dd></div>
        <div class="app-details__item"><dt>{{ __('Joined on') }}</dt><dd><span class="app-num">{{ $employee->joined_on->format('Y-m-d') }}</span></dd></div>
        <div class="app-details__item"><dt>{{ __('Nationality') }}</dt><dd>{{ Lookup::label('country', $employee->nationality) ?: '—' }}</dd></div>
        <div class="app-details__item"><dt>{{ __('Phone') }}</dt><dd>@if($employee->phone)<a href="tel:{{ $employee->phone }}" class="app-ltr">{{ $employee->phone }}</a>@else — @endif</dd></div>
        <div class="app-details__item"><dt>{{ __('E-mail') }}</dt><dd>@if($employee->email)<a href="mailto:{{ $employee->email }}" class="app-ltr">{{ $employee->email }}</a>@else — @endif</dd></div>
        <div class="app-details__item"><dt>{{ __('Their user account') }}</dt><dd>{{ $employee->user?->name ?? __('None') }}</dd></div>
        @if($employee->emergency_contact)<div class="app-details__item app-profile__wide"><dt>{{ __('Emergency contact') }}</dt><dd dir="auto">{{ $employee->emergency_contact }}</dd></div>@endif
        @if($seesPrivate)
            <div class="app-details__item"><dt><i class="bi bi-lock" aria-hidden="true"></i> {{ __('ID or passport number') }}</dt><dd><span class="app-ltr">{{ $employee->id_number ?: '—' }}</span></dd></div>
            <div class="app-details__item"><dt><i class="bi bi-lock" aria-hidden="true"></i> {{ __('Date of birth') }}</dt><dd><span class="app-num">{{ $employee->birth_date?->format('Y-m-d') ?? '—' }}</span></dd></div>
            @if($employee->address)<div class="app-details__item app-profile__wide"><dt><i class="bi bi-lock" aria-hidden="true"></i> {{ __('Address') }}</dt><dd dir="auto">{{ $lines($employee->address) }}</dd></div>@endif
            @if($employee->bank_details)<div class="app-details__item app-profile__wide"><dt><i class="bi bi-lock" aria-hidden="true"></i> {{ __('Salary bank account') }}</dt><dd dir="auto">{{ $lines($employee->bank_details) }}</dd></div>@endif
        @endif
        @if($employee->notes)<div class="app-details__item app-profile__wide"><dt>{{ __('Notes') }}</dt><dd dir="auto">{{ $lines($employee->notes) }}</dd></div>@endif
    </dl>
</section>

<div class="app-ptabs" data-app-tabs data-default="documents">
    <nav class="app-ptabs__nav" aria-label="{{ __('Sections of this page') }}">
        @foreach($tabs as $id => [$icon, $name, $count])
            <a href="#{{ $id }}" class="app-ptabs__link {{ $id === 'documents' ? 'is-default' : '' }}" data-app-tab-link>
                <i class="bi bi-{{ $icon }}" aria-hidden="true"></i><span>{{ $name }}</span>
                @if($count !== null)<span class="app-ptabs__count app-num">{{ $count }}</span>@endif
            </a>
        @endforeach
    </nav>
    <div class="app-ptabs__panels">
        <section class="app-ptabs__panel is-default" id="documents" aria-labelledby="documents-head" tabindex="-1" data-app-tab-panel>
            @include('hr.employees._documents')
        </section>
        @if($seesSalary)
            <section class="app-ptabs__panel" id="salary" aria-labelledby="salary-head" tabindex="-1" data-app-tab-panel>
                @include('hr.employees._salary')
            </section>
        @endif
        <section class="app-ptabs__panel" id="leave" aria-labelledby="leave-head" tabindex="-1" data-app-tab-panel>
            @include('hr.employees._leave')
        </section>
        <section class="app-ptabs__panel" id="attendance" aria-labelledby="attendance-head" tabindex="-1" data-app-tab-panel>
            @include('hr.employees._attendance')
        </section>
        @can('employees.edit')
            <section class="app-ptabs__panel" id="files" aria-labelledby="files-head" tabindex="-1" data-app-tab-panel>
                <h2 class="visually-hidden" id="files-head">{{ __('Files') }}</h2>
                <div class="app-ptabs__attach">
                    @include('partials.attachments', ['attachable' => $employee, 'type' => 'employees'])
                </div>
            </section>
        @endcan
    </div>
</div>
@endsection

@include('partials.ptabs-script')
