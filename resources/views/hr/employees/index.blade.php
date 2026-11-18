@extends('layouts.app')

@section('title', __('Employees'))

@section('content')
@php use App\Models\Lookup; $today = now()->toDateString(); @endphp
<x-page-header :title="__('Employees')" :subtitle="__('The staff file: who works at MASDAR, their documents, leave and pay.')">
    @can('employees.edit')
        <x-slot:actions>
            <a href="{{ route('employees.create') }}" class="btn btn-primary"><i class="bi bi-person-plus" aria-hidden="true"></i>{{ __('New employee') }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('On the staff')" :value="$counts['staff']" icon="people" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('On leave today')" :value="$counts['onLeave']" icon="calendar-x" :href="Gate::allows('leave.view') ? route('attendance.index') : null" /></div>
    @can('employees.edit')
        <div class="col-6 col-lg-3"><x-kpi :label="__('Documents to renew')" :value="$counts['expiring']" icon="passport" :tone="$counts['expiring'] ? 'late' : 'done'" :href="route('employees.index', ['documents' => 'expiring'])" /></div>
    @endcan
    @if($counts['pendingLeave'] !== null)
        <div class="col-6 col-lg-3"><x-kpi :label="__('Leave to decide')" :value="$counts['pendingLeave']" icon="hourglass-split" :tone="$counts['pendingLeave'] ? 'due' : 'done'" :href="route('leave.index', ['status' => 'pending'])" /></div>
    @endif
</div>

<form method="GET" class="app-card app-filterbar mb-3" role="search">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small" for="q">{{ __('Search') }}</label>
            <input type="search" name="q" id="q" value="{{ $filters['q'] }}" class="form-control" placeholder="{{ __('Name, code or job') }}">
        </div>
        <div class="col-md-3">
            <label class="form-label small" for="department">{{ __('Department') }}</label>
            <select name="department" id="department" class="form-select">
                <option value="">{{ __('All departments') }}</option>
                @foreach($departments as $code => $name)<option value="{{ $code }}" @selected($filters['department'] === $code)>{{ $name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small" for="status">{{ __('Who') }}</label>
            <select name="status" id="status" class="form-select">
                <option value="current" @selected($filters['status'] === 'current')>{{ __('On the staff') }}</option>
                <option value="left" @selected($filters['status'] === 'left')>{{ __('Left') }}</option>
                <option value="all" @selected($filters['status'] === 'all')>{{ __('Everyone') }}</option>
            </select>
        </div>
        @if($filters['documents'])<input type="hidden" name="documents" value="expiring">@endif
        <div class="col-md-2 d-grid"><button class="btn btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Filter') }}</button></div>
    </div>
    @if($filters['documents'])
        <div class="mt-2 small"><x-status tone="late" icon="passport">{{ __('Only employees with documents to renew') }}</x-status> <a href="{{ route('employees.index') }}" class="ms-2">{{ __('Show everyone') }}</a></div>
    @endif
</form>

@if($employees->isEmpty())
    <div class="app-card">
        <x-empty icon="person-vcard" :title="__('No employees to show.')" :text="__('Add each member of staff once: their documents, leave and pay hang from their file.')">
            @can('employees.edit')
                <x-slot:action><a href="{{ route('employees.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-person-plus" aria-hidden="true"></i>{{ __('New employee') }}</a></x-slot:action>
            @endcan
        </x-empty>
    </div>
@else
    <x-section :title="__('Staff')" icon="people" :subtitle="trans_choice('{1} one person|[2,*] :count people', $employees->count(), ['count' => $employees->count()])" flush>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 app-hr-staff">
                <thead><tr>
                    <th scope="col">{{ __('Employee') }}</th><th scope="col">{{ __('Job') }}</th><th scope="col">{{ __('Work location') }}</th>
                    <th scope="col">{{ __('Joined on') }}</th><th scope="col">{{ __('Status') }}</th>
                </tr></thead>
                <tbody>
                @foreach($employees as $e)
                    @php $left = $e->left_on && $e->left_on->toDateString() < $today; $docs = $expiring->get($e->id); @endphp
                    <tr class="{{ $left ? 'text-muted' : '' }}">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <x-avatar :name="$e->name" :size="34" />
                                <div class="min-w-0">
                                    <a href="{{ route('employees.show', $e) }}" class="fw-semibold">{{ $e->name }}</a>
                                    <div class="small text-muted"><span class="app-ltr">{{ $e->code }}</span>@if($e->name_local) · <bdi>{{ $e->name_local }}</bdi>@endif</div>
                                </div>
                            </div>
                        </td>
                        <td>{{ $e->job_title ?: '—' }}@if($e->department)<div class="small text-muted">{{ $departments[$e->department] ?? $e->department }}</div>@endif</td>
                        <td>{{ $e->work_location ? ($locations[$e->work_location] ?? $e->work_location) : '—' }}</td>
                        <td><span class="app-num">{{ $e->joined_on->format('Y-m-d') }}</span></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                @if($left)
                                    <x-status tone="skip" icon="box-arrow-right">{{ __('Left') }} <span class="app-num">{{ $e->left_on->format('Y-m-d') }}</span></x-status>
                                @elseif($onLeaveToday->contains($e->id))
                                    <x-status tone="info" icon="calendar-x">{{ __('On leave') }}</x-status>
                                @else
                                    <x-status tone="done">{{ __('On the staff') }}</x-status>
                                @endif
                                @if($docs)
                                    @php $expired = $docs->where('days_left', '<', 0)->isNotEmpty(); @endphp
                                    <a href="{{ route('employees.show', $e) }}#documents" class="text-decoration-none">
                                        <x-status :tone="$expired ? 'late' : 'due'" icon="passport">{{ $expired ? __('Document expired') : __('Document expiring') }}</x-status>
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-section>
@endif
@endsection
