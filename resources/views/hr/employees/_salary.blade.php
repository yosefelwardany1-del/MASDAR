@php use App\Services\Accounting\Decimal; @endphp
<h2 class="visually-hidden" id="salary-head">{{ __('Salary') }}</h2>
<div class="row g-3">
    <div class="col-lg-4">
        <x-section :title="__('Salary now')" icon="cash-stack">
            @if($salaryNow)
                <div class="app-hr-salary">
                    <span class="app-hr-salary__amount app-num">{{ Decimal::format(bcadd((string) $salaryNow->base_salary, (string) $salaryNow->allowances, 4)) }}</span>
                    <span class="app-unit">{{ $salaryNow->currency }} / {{ __('month') }}</span>
                </div>
                <x-detail-list :cols="1" :items="[
                    __('Base salary') => new \Illuminate\Support\HtmlString('<span class=\'app-num\'>'.e(Decimal::format($salaryNow->base_salary)).'</span>'),
                    __('Fixed allowances') => new \Illuminate\Support\HtmlString('<span class=\'app-num\'>'.e(Decimal::format($salaryNow->allowances)).'</span>'),
                    __('Since') => new \Illuminate\Support\HtmlString('<span class=\'app-num\'>'.e($salaryNow->effective_from->format('Y-m-d')).'</span>'),
                ]" />
            @else
                <x-empty icon="cash-stack" :title="__('No salary set.')" :text="__('Payroll leaves out an employee without a salary.')" />
            @endif
        </x-section>
    </div>
    <div class="col-lg-8">
        <x-section :title="__('Salary history')" icon="clock-history" :subtitle="__('Each change is a new row; nothing is overwritten.')" flush>
            @if($salaries->isEmpty())
                <div class="app-panel-empty"><i class="bi bi-dash-circle" aria-hidden="true"></i>{{ __('No salary yet.') }}</div>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th scope="col">{{ __('From') }}</th><th scope="col" class="text-end">{{ __('Base salary') }}</th><th scope="col" class="text-end">{{ __('Fixed allowances') }}</th><th scope="col">{{ __('Note') }}</th><th scope="col">{{ __('By') }}</th></tr></thead>
                        <tbody>
                        @foreach($salaries as $s)
                            <tr class="{{ $salaryNow && $salaryNow->id === $s->id ? 'app-row--current' : '' }}">
                                <td><span class="app-num">{{ $s->effective_from->format('Y-m-d') }}</span></td>
                                <td class="app-figcell"><span class="app-num app-fig">{{ Decimal::format($s->base_salary) }}</span><span class="app-unit">{{ $s->currency }}</span></td>
                                <td class="app-figcell"><span class="app-num app-fig">{{ Decimal::format($s->allowances) }}</span><span class="app-unit">{{ $s->currency }}</span></td>
                                <td class="small" dir="auto">{{ $s->note }}</td>
                                <td class="small">{{ $s->creator?->name }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @can('payroll.edit')
                <form method="POST" action="{{ route('employees.salaries.store', $employee) }}" class="app-hr-inlineform border-top">
                    @csrf
                    <div class="app-hr-inlineform__title"><i class="bi bi-plus-circle" aria-hidden="true"></i>{{ __('A new salary, or a correction') }}</div>
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="sal-from">{{ __('From') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="date" name="effective_from" id="sal-from" class="form-control @error('effective_from') is-invalid @enderror" value="{{ old('effective_from', now()->startOfMonth()->toDateString()) }}" required>
                            @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="sal-base">{{ __('Base salary') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input name="base_salary" id="sal-base" inputmode="decimal" dir="ltr" class="form-control app-num @error('base_salary') is-invalid @enderror" value="{{ old('base_salary') }}" required>
                            @error('base_salary')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small" for="sal-allow">{{ __('Fixed allowances') }}</label>
                            <input name="allowances" id="sal-allow" inputmode="decimal" dir="ltr" class="form-control app-num @error('allowances') is-invalid @enderror" value="{{ old('allowances', '0') }}">
                            @error('allowances')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small" for="sal-cur">{{ __('Currency') }}</label>
                            <select name="currency" id="sal-cur" class="form-select">
                                @foreach($currencies as $code => $name)<option value="{{ $code }}" @selected(old('currency', $employee->salary_currency) === $code)>{{ $code }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-2 d-grid"><button class="btn btn-primary mt-md-4"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button></div>
                        <div class="col-12">
                            <label class="visually-hidden" for="sal-note">{{ __('Note') }}</label>
                            <input name="note" id="sal-note" dir="auto" class="form-control form-control-sm" placeholder="{{ __('Why (a raise, a promotion, a correction)') }}" value="{{ old('note') }}">
                        </div>
                    </div>
                </form>
            @endcan
        </x-section>
    </div>
</div>
