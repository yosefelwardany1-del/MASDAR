@extends('layouts.app')

@section('title', $line->employee->name.' · '.__('Payroll'))

@php
    use App\Services\Accounting\Decimal;
    $fields = [
        __('Earnings') => ['overtime_hours' => __('Overtime hours'), 'overtime_pay' => __('Overtime pay'), 'bonus' => __('Bonus')],
        __('Unpaid days') => ['unpaid_days' => __('Unpaid days'), 'unpaid_deduction' => __('Deduction for unpaid days')],
        __('Deductions from pay') => ['social_insurance_employee' => __('Social insurance (employee)'), 'housing_fund_employee' => __('Housing fund (employee)'), 'income_tax' => __('Individual income tax'), 'other_deductions' => __('Other deductions (e.g. an advance)')],
        __('Paid by MASDAR on top') => ['social_insurance_employer' => __('Social insurance (employer)'), 'housing_fund_employer' => __('Housing fund (employer)')],
    ];
    $plain = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
@endphp

@section('content')
<x-page-header :title="$line->employee->name" :subtitle="$run->periodLabel().' · '.$run->currency.' · '.__('Base :base, allowances :allowances', ['base' => Decimal::format($line->base_salary), 'allowances' => Decimal::format($line->allowances)])"
               :back="route('payroll.show', $run).'#line-'.$line->id" :back-label="__('Payroll')" />

<form method="POST" action="{{ route('payroll.lines.update', [$run, $line]) }}" class="app-form">
    @csrf @method('PUT')
    @error('line')<div class="alert alert-danger">{{ $message }}</div>@enderror
    @foreach($fields as $group => $inputs)
        <x-section :title="$group" icon="calculator">
            <div class="row g-3">
                @foreach($inputs as $name => $label)
                    <div class="col-md-3">
                        <label class="form-label" for="ln-{{ $name }}">{{ $label }}</label>
                        <input name="{{ $name }}" id="ln-{{ $name }}" inputmode="decimal" dir="ltr" class="form-control app-num @error($name) is-invalid @enderror" value="{{ old($name, $plain($line->{$name})) }}">
                        @error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
        </x-section>
    @endforeach
    <x-section :title="__('Note')" icon="chat-left-text">
        <label class="visually-hidden" for="ln-note">{{ __('Note') }}</label>
        <input name="note" id="ln-note" dir="auto" class="form-control" value="{{ old('note', $line->note) }}" placeholder="{{ __('Shown on the payslip') }}">
    </x-section>
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('payroll.show', $run) }}#line-{{ $line->id }}" class="btn btn-light">{{ __('Cancel') }}</a>
        <span class="app-form__note">{{ __('Gross and net are worked out from these when saved.') }}</span>
    </div>
</form>
@endsection
