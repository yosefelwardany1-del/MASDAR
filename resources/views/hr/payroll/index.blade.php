@extends('layouts.app')

@section('title', __('Payroll'))

@section('content')
@php use App\Models\PayrollRun; use App\Services\Accounting\Decimal; @endphp
<x-page-header :title="__('Payroll')" :subtitle="__('A run per month and currency: prepared from salaries, attendance and leave, approved by a manager, then posted and paid.')" />

@if($ratesMissing)
    <div class="alert alert-warning d-flex gap-2 align-items-start" role="status">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>{{ __('No social insurance or housing fund rate is set, so none is deducted. Set the rates on Lists & options, or enter the amounts on each line.') }}
            @can('configuration.view')<a href="{{ route('configuration.index') }}#hr-payroll" class="ms-1">{{ __('Lists & options') }}</a>@endcan</div>
    </div>
@endif

<div class="row g-3">
    @can('payroll.edit')
        <div class="col-xl-4 order-xl-2">
            <x-section :title="__('Prepare a month')" icon="plus-circle">
                <form method="POST" action="{{ route('payroll.store') }}" class="app-form">
                    @csrf
                    <div class="row g-3">
                        <div class="col-7">
                            <label class="form-label" for="pr-period">{{ __('Month') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="month" name="period" id="pr-period" class="form-control @error('period') is-invalid @enderror" value="{{ old('period', $nextMonth) }}" required>
                            @error('period')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="pr-currency">{{ __('Currency') }}</label>
                            <select name="currency" id="pr-currency" class="form-select @error('currency') is-invalid @enderror">
                                @foreach($currencies as $code => $name)<option value="{{ $code }}" @selected(old('currency', $defaultCurrency) === $code)>{{ $code }}</option>@endforeach
                            </select>
                            @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="pr-pay">{{ __('Pay day') }}</label>
                            <input type="date" name="pay_date" id="pr-pay" class="form-control @error('pay_date') is-invalid @enderror" value="{{ old('pay_date') }}">
                        </div>
                        <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-calculator" aria-hidden="true"></i>{{ __('Prepare the draft') }}</button></div>
                    </div>
                </form>
                <p class="app-note mt-3 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('One line per employee paid in that currency, from their salary, overtime, absences and unpaid leave. Check each line before approval.') }}</span></p>
            </x-section>
        </div>
    @endcan
    <div class="{{ Gate::allows('payroll.edit') ? 'col-xl-8' : 'col-12' }}">
        <x-section :title="__('Payroll runs')" icon="wallet2" flush>
            @if($runs->isEmpty())
                <x-empty icon="wallet2" :title="__('No payroll yet.')" :text="__('Set each employee\'s salary on their page, then prepare the first month.')" />
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th scope="col">{{ __('Payroll run') }}</th><th scope="col">{{ __('Month') }}</th><th scope="col" class="text-end">{{ __('People') }}</th><th scope="col" class="text-end">{{ __('Net pay') }}</th><th scope="col">{{ __('Paid') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
                        <tbody>
                        @foreach($runs as $run)
                            @php $p = $paid[$run->id]; $net = (string) ($run->lines_sum_net ?? '0'); @endphp
                            <tr class="{{ $run->status === 'void' ? 'text-muted' : '' }}">
                                <td><a href="{{ route('payroll.show', $run) }}" class="fw-semibold app-ltr">{{ $run->isDraft() ? __('Draft') : $run->number }}</a></td>
                                <td>{{ $run->periodLabel() }}</td>
                                <td class="text-end"><span class="app-num">{{ $run->lines_count }}</span></td>
                                <td class="app-figcell"><span class="app-num app-fig">{{ Decimal::format($net) }}</span><span class="app-unit">{{ $run->currency }}</span></td>
                                <td style="min-inline-size: 9rem">
                                    @if($p !== null && Decimal::isPositive($net))
                                        @php $pct = min(100, round((float) $p / (float) $net * 100)); @endphp
                                        <x-progress :value="$pct" :tone="$pct >= 100 ? 'success' : 'primary'" thin :sr-label="__('Paid')" :caption="$pct.'%'" />
                                    @else — @endif
                                </td>
                                <td><x-status :tone="PayrollRun::statusTone($run->status)">{{ PayrollRun::statusLabel($run->status) }}</x-status></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-section>
        <div class="mt-3">{{ $runs->links() }}</div>
    </div>
</div>
@endsection
