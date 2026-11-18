@php use App\Models\LeaveRequest; $fmtDays = fn ($d) => $d === null ? '—' : rtrim(rtrim((string) $d, '0'), '.'); @endphp
<h2 class="visually-hidden" id="leave-head">{{ __('Leave') }}</h2>
<div class="row g-3">
    <div class="col-xl-5">
        <x-section :title="__('Balances')" icon="pie-chart" :subtitle="(string) $year" flush>
            <x-slot:actions>
                <a href="{{ route('employees.show', ['employee' => $employee, 'year' => $year - 1]) }}#leave" class="btn btn-sm btn-light" aria-label="{{ __('Previous year') }}"><i class="bi bi-chevron-{{ \App\Http\Middleware\SetUserLocale::isRtl() ? 'right' : 'left' }}" aria-hidden="true"></i></a>
                <a href="{{ route('employees.show', ['employee' => $employee, 'year' => $year + 1]) }}#leave" class="btn btn-sm btn-light" aria-label="{{ __('Next year') }}"><i class="bi bi-chevron-{{ \App\Http\Middleware\SetUserLocale::isRtl() ? 'left' : 'right' }}" aria-hidden="true"></i></a>
            </x-slot:actions>
            @include('hr._balances', ['balances' => $balances])
            @can('leave.edit')
                <form method="POST" action="{{ route('employees.allowances.store', $employee) }}" class="app-hr-inlineform border-top">
                    @csrf
                    <input type="hidden" name="year" value="{{ $year }}">
                    <div class="app-hr-inlineform__title"><i class="bi bi-sliders" aria-hidden="true"></i>{{ __('Their own allowance for :year', ['year' => $year]) }}</div>
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="visually-hidden" for="alw-type">{{ __('Kind of leave') }}</label>
                            <select name="leave_type" id="alw-type" class="form-select form-select-sm">
                                @foreach($leaveTypes as $code => $t)<option value="{{ $code }}">{{ $t['label'] }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="visually-hidden" for="alw-days">{{ __('Days') }}</label>
                            <input name="days" id="alw-days" inputmode="decimal" dir="ltr" class="form-control form-control-sm app-num @error('days') is-invalid @enderror" placeholder="{{ __('Days') }}" required>
                        </div>
                        <div class="col-2 d-grid"><button class="btn btn-sm btn-light" title="{{ __('Save') }}" aria-label="{{ __('Save') }}"><i class="bi bi-check2" aria-hidden="true"></i></button></div>
                    </div>
                    <div class="form-text">{{ __('e.g. statutory annual leave of 5, 10 or 15 days by years worked.') }}</div>
                </form>
            @endcan
        </x-section>
    </div>
    <div class="col-xl-7">
        <x-section :title="__('Requests')" icon="calendar-check" flush>
            @can('leave.view')
                <x-slot:actions><a href="{{ route('leave.index') }}" class="small fw-medium">{{ __('All leave') }}</a></x-slot:actions>
            @endcan
            @include('hr._requests', ['requests' => $requests, 'showEmployee' => false, 'selfService' => false])
        </x-section>
    </div>
</div>
