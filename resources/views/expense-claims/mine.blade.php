@extends('layouts.app')

@section('title', __('My expenses'))

@php use App\Services\Accounting\Decimal; @endphp

@section('content')
<x-page-header :title="__('My expenses')" :subtitle="__('Money you spent for MASDAR — taxis, samples, courier, meals with clients — claimed back with the receipts.')" />

<form method="POST" action="{{ route('my-expenses.store') }}" class="app-card p-3 mb-4 app-form">
    @csrf
    <div class="row g-2 align-items-end">
        <div class="col-md-6"><label class="form-label" for="title">{{ __('New claim') }}</label><input id="title" name="title" value="{{ old('title') }}" class="form-control @error('title') is-invalid @enderror" placeholder="{{ __('e.g. Ningbo port visit, November') }}" required dir="auto">@error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-6 col-md-3"><label class="form-label" for="currency">{{ __('Currency') }}</label><select id="currency" name="currency" class="form-select">@foreach($currencies as $code => $label)<option value="{{ $code }}" @selected(old('currency', 'CNY') === $code)>{{ $code }}</option>@endforeach</select></div>
        <div class="col-6 col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Start the claim') }}</button></div>
    </div>
</form>

<x-section :title="__('My claims')" icon="receipt-cutoff" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Claim') }}</th><th scope="col">{{ __('Title') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>
            @forelse($claims as $c)
                <tr>
                    <td><a href="{{ route('my-expenses.show', $c) }}" class="fw-semibold app-ltr">{{ $c->number }}</a></td>
                    <td dir="auto">{{ $c->title }}</td>
                    <td class="text-end"><span class="app-num">{{ Decimal::format((string) ($c->lines_sum_amount ?? 0)) }}</span> <span class="app-unit">{{ $c->currency }}</span></td>
                    <td>@include('expense-claims._status', ['claim' => $c, 'paidCount' => $c->paid_count])</td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-0"><x-empty icon="receipt-cutoff" :title="__('No claims yet.')" :text="__('Start a claim, add each receipt with its photo, then submit it.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
