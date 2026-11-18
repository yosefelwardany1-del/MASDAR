@extends('layouts.app')

@php
    use App\Models\Payment;
    use App\Models\PayrollRun;
    use App\Services\Accounting\Decimal;
    $f = fn ($v) => Decimal::format($v);
    $title = $run->isDraft() ? __('Payroll :month (draft)', ['month' => $run->periodLabel()]) : __('Payroll :number', ['number' => $run->number]);
    $unpaid = bcsub($totals['net'], $paid, 4);
    $employer = bcadd($totals['social_insurance_employer'], $totals['housing_fund_employer'], 4);
@endphp

@section('title', $title)

@section('content')
<x-page-header :title="$title" :subtitle="$run->periodLabel().' · '.$run->currency" :back="route('payroll.index')" :back-label="__('Payroll')">
    <x-slot:meta><x-status :tone="PayrollRun::statusTone($run->status)">{{ PayrollRun::statusLabel($run->status) }}</x-status></x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('payroll.payslips', $run) }}" class="btn btn-light" target="_blank"><i class="bi bi-printer" aria-hidden="true"></i>{{ __('Payslips') }}</a>
        @if($run->isDraft())
            @can('payroll.edit')
                <form method="POST" action="{{ route('payroll.recalculate', $run) }}" onsubmit="return confirm(@js(__('Rebuild every line from salaries, attendance and leave? Amounts typed in are lost.')));">@csrf<button class="btn btn-light"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Recalculate') }}</button></form>
            @endcan
            @can('payroll.approve')
                <form method="POST" action="{{ route('payroll.approve', $run) }}" onsubmit="return confirm(@js(__('Approve this payroll? It can no longer change; it is then posted and paid.')));">@csrf<button class="btn btn-primary"><i class="bi bi-check2-circle" aria-hidden="true"></i>{{ __('Approve') }}</button></form>
            @endcan
        @elseif($run->isApproved())
            @can('payments.edit')
                @if(Decimal::isPositive($unpaid))
                    <a href="{{ route('payments.create', ['kind' => 'salary_payment', 'payroll' => $run->id]) }}" class="btn btn-primary"><i class="bi bi-cash-coin" aria-hidden="true"></i>{{ __('Record salary payment') }}</a>
                @endif
            @endcan
        @endif
    </x-slot:actions>
</x-page-header>

@if($run->isDraft() && $ratesMissing)
    <div class="alert alert-warning d-flex gap-2" role="status"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>{{ __('No social insurance or housing fund rate is set, so none is deducted. Set the rates on Lists & options, or enter the amounts on each line.') }}</div></div>
@endif
@if($run->isDraft() && ! $hasHolidays)
    <div class="alert alert-warning d-flex gap-2" role="status"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>{{ __('No public holiday is entered for :year: joiners, leavers and unpaid days are counted on every weekday.', ['year' => $run->period->year]) }}</div></div>
@endif
@if($run->status === 'void')
    <div class="alert alert-secondary d-flex gap-2" role="status"><i class="bi bi-slash-circle" aria-hidden="true"></i><div>{{ __('Voided by :name on :date: :reason', ['name' => $run->voider?->name, 'date' => $run->voided_at?->format('Y-m-d'), 'reason' => $run->void_reason]) }}</div></div>
@endif

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('Gross pay')" :value="$f($totals['gross']).' '.$run->currency" icon="cash-stack" :hint="trans_choice('{1} one person|[2,*] :count people', (int) $totals['lines'], ['count' => (int) $totals['lines']])" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Net pay')" :value="$f($totals['net']).' '.$run->currency" icon="wallet2" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Employer contributions')" :value="$f($employer).' '.$run->currency" icon="building" /></div>
    <div class="col-6 col-lg-3">
        @if($run->isApproved())
            <x-kpi :label="__('Still to pay')" :value="$f($unpaid).' '.$run->currency" icon="hourglass-split" :tone="Decimal::isPositive($unpaid) ? 'due' : 'done'" :hint="__('Paid :amount', ['amount' => $f($paid)])" />
        @else
            <x-kpi :label="__('Income tax withheld')" :value="$f($totals['income_tax']).' '.$run->currency" icon="bank" />
        @endif
    </div>
</div>

<x-section :title="__('Lines')" icon="people" flush class="mb-4">
    @if($run->isDraft())
        <x-slot:actions><span class="small text-muted">{{ __('Income tax is entered per line, as the tax bureau\'s system computes it.') }}</span></x-slot:actions>
    @endif
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 app-hr-payroll">
            <thead>
                <tr>
                    <th scope="col">{{ __('Employee') }}</th>
                    <th scope="col" class="text-end">{{ __('Base') }}</th>
                    <th scope="col" class="text-end">{{ __('Allowances') }}</th>
                    <th scope="col" class="text-end">{{ __('Overtime') }}</th>
                    <th scope="col" class="text-end">{{ __('Bonus') }}</th>
                    <th scope="col" class="text-end">{{ __('Unpaid days') }}</th>
                    <th scope="col" class="text-end">{{ __('Gross') }}</th>
                    <th scope="col" class="text-end">{{ __('Social insurance & fund') }}</th>
                    <th scope="col" class="text-end">{{ __('Tax') }}</th>
                    <th scope="col" class="text-end">{{ __('Other') }}</th>
                    <th scope="col" class="text-end">{{ __('Net pay') }}</th>
                    <th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
            @foreach($run->lines as $line)
                <tr id="line-{{ $line->id }}">
                    <th scope="row"><a href="{{ route('employees.show', $line->employee_id) }}#salary" class="d-block">{{ $line->employee->name }}</a><span class="d-block small text-muted"><span class="app-ltr">{{ $line->employee->code }}</span></span>@if($line->note)<span class="d-block small text-muted" dir="auto">{{ $line->note }}</span>@endif</th>
                    <td class="text-end"><span class="app-num">{{ $f($line->base_salary) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($line->allowances) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($line->overtime_pay) }}</span>@if((float) $line->overtime_hours > 0)<div class="small text-muted"><span class="app-num">{{ rtrim(rtrim((string) $line->overtime_hours, '0'), '.') }}</span> {{ __('h') }}</div>@endif</td>
                    <td class="text-end"><span class="app-num">{{ $f($line->bonus) }}</span></td>
                    <td class="text-end">@if((float) $line->unpaid_days > 0)<span class="app-num text-danger">−{{ $f($line->unpaid_deduction) }}</span><div class="small text-muted">{{ trans_choice('{1} :n day|[0,*] :n days', (float) $line->unpaid_days == 1.0 ? 1 : 2, ['n' => rtrim(rtrim((string) $line->unpaid_days, '0'), '.')]) }}</div>@else — @endif</td>
                    <td class="text-end"><span class="app-num fw-semibold">{{ $f($line->gross) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f(bcadd((string) $line->social_insurance_employee, (string) $line->housing_fund_employee, 4)) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($line->income_tax) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($line->other_deductions) }}</span></td>
                    <td class="text-end"><span class="app-num fw-bold">{{ $f($line->net) }}</span></td>
                    <td class="text-end text-nowrap">
                        <span class="app-rowactions">
                            @if($run->isDraft())
                                @can('payroll.edit')<a href="{{ route('payroll.lines.edit', [$run, $line]) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}: {{ $line->employee->name }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>@endcan
                            @endif
                            <a href="{{ route('payroll.payslips', [$run, 'line' => $line->id]) }}" target="_blank" class="btn btn-sm btn-light" title="{{ __('Payslip') }}" aria-label="{{ __('Payslip') }}: {{ $line->employee->name }}"><i class="bi bi-printer" aria-hidden="true"></i></a>
                        </span>
                    </td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="app-hr-payroll__total">
                    <th scope="row">{{ __('Total') }}</th>
                    <td class="text-end"><span class="app-num">{{ $f($totals['base_salary']) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($totals['allowances']) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($totals['overtime_pay']) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($totals['bonus']) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ Decimal::isPositive($totals['unpaid_deduction']) ? '−'.$f($totals['unpaid_deduction']) : '—' }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($totals['gross']) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f(bcadd($totals['social_insurance_employee'], $totals['housing_fund_employee'], 4)) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($totals['income_tax']) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($totals['other_deductions']) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $f($totals['net']) }}</span></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-section>

<div class="row g-3">
    <div class="col-lg-6">
        <x-section :title="__('The run')" icon="info-circle">
            <x-detail-list :cols="2" :items="[
                __('Month') => $run->periodLabel(),
                __('Currency') => $run->currency,
                __('Pay day') => $run->pay_date?->format('Y-m-d'),
                __('Prepared by') => $run->creator ? new \Illuminate\Support\HtmlString('<bdi>'.e($run->creator->name).'</bdi>') : null,
                __('Approved by') => $run->approver ? new \Illuminate\Support\HtmlString('<bdi>'.e($run->approver->name).'</bdi> · <span class=\'app-num\'>'.e($run->approved_at?->format('Y-m-d')).'</span>') : null,
                __('Journal entry') => $entry ? new \Illuminate\Support\HtmlString(Gate::allows('accounting.view') ? '<a href=\''.e(route('accounting.journal.show', $entry)).'\' class=\'app-ltr\'>'.e($entry->number).'</a>' : e($entry->number)) : ($run->isApproved() ? __('Waiting in the posting queue') : null),
            ]" />
            @if($run->note)<p class="mt-3 mb-0" dir="auto">{{ $run->note }}</p>@endif
            @if($run->isDraft())
                @can('payroll.edit')
                    <form method="POST" action="{{ route('payroll.update', $run) }}" class="app-hr-inlineform border-top mt-3 px-0 pb-0">
                        @csrf @method('PUT')
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4"><label class="form-label small" for="run-pay">{{ __('Pay day') }}</label><input type="date" name="pay_date" id="run-pay" class="form-control" value="{{ $run->pay_date?->format('Y-m-d') }}"></div>
                            <div class="col-md-6"><label class="form-label small" for="run-note">{{ __('Note') }}</label><input name="note" id="run-note" dir="auto" class="form-control" value="{{ $run->note }}"></div>
                            <div class="col-md-2 d-grid"><button class="btn btn-light">{{ __('Save') }}</button></div>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('payroll.destroy', $run) }}" class="mt-3" onsubmit="return confirm(@js(__('Delete this draft?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i>{{ __('Delete the draft') }}</button></form>
                @endcan
            @endif
        </x-section>
    </div>
    <div class="col-lg-6">
        <x-section :title="__('Salary payments')" icon="cash-coin" flush>
            @if($payments->isEmpty())
                <div class="app-panel-empty"><i class="bi bi-dash-circle" aria-hidden="true"></i>{{ $run->isApproved() ? __('Nothing paid yet.') : __('Salaries are paid once the run is approved.') }}</div>
            @else
                <ul class="app-list">
                    @foreach($payments as $p)
                        <li class="app-list__row">
                            <div class="app-list__main">
                                <a href="{{ route('payments.show', $p) }}" class="app-list__title app-ltr">{{ $p->number }}</a>
                                <div class="app-list__sub"><span class="app-num">{{ $p->paid_on->format('Y-m-d') }}</span> · {{ $p->moneyAccount?->name }}</div>
                            </div>
                            <span class="app-num {{ $p->reverses_payment_id ? 'text-danger' : 'fw-semibold' }}">{{ $p->reverses_payment_id ? '−' : '' }}{{ $f($p->amount) }} {{ $p->currency }}</span>
                            @if(! $p->approval && ! $p->reverses_payment_id)<x-status tone="due">{{ __('Awaiting approval') }}</x-status>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($run->isApproved())
                @can('payroll.approve')
                    <details class="app-hr-inlineform border-top">
                        <summary class="small text-danger">{{ __('Void this run') }}</summary>
                        <form method="POST" action="{{ route('payroll.void', $run) }}" class="mt-2">
                            @csrf
                            <label class="form-label small" for="void-reason">{{ __('Why') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input name="void_reason" id="void-reason" class="form-control mb-2 @error('void_reason') is-invalid @enderror" required>
                            @error('void_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @error('run')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                            <p class="small text-muted mb-2">{{ __('Reverse its salary payments and its journal entry first. The month can then be run again.') }}</p>
                            <button class="btn btn-sm btn-outline-danger">{{ __('Void the run') }}</button>
                        </form>
                    </details>
                @endcan
            @endif
        </x-section>
    </div>
</div>
@endsection
