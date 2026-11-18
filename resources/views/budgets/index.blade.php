@extends('layouts.app')

@section('title', __('Budgets'))

@section('content')
<x-page-header :title="__('Budgets')" :eyebrow="__('Finance')" :subtitle="__('A plan per income and expense account and month, and how the year is going against it from the posted books.')" />

@can('budgets.edit')
<form method="POST" action="{{ route('budgets.store') }}" class="app-card p-3 mb-4 app-form">
    @csrf
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label" for="name">{{ __('New budget') }}</label><input id="name" name="name" value="{{ old('name', __('Main budget')) }}" class="form-control @error('name') is-invalid @enderror" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-6 col-md-2"><label class="form-label" for="year">{{ __('Year') }}</label><input id="year" name="year" type="number" min="2000" max="2100" value="{{ old('year', now()->year + (now()->month >= 11 ? 1 : 0)) }}" class="form-control" required></div>
        <div class="col-6 col-md-3"><label class="form-label" for="copy_from">{{ __('Start from') }}</label><select id="copy_from" name="copy_from" class="form-select"><option value="">{{ __('Empty') }}</option>@foreach($budgets as $b)<option value="{{ $b->id }}">{{ $b->name }} {{ $b->year }}</option>@endforeach</select></div>
        <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Create') }}</button></div>
    </div>
</form>
@endcan

<x-section :title="__('Budgets')" icon="clipboard-data" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Budget') }}</th><th scope="col">{{ __('Year') }}</th><th scope="col" class="text-end">{{ __('Amounts entered') }}</th></tr></thead>
            <tbody>
            @forelse($budgets as $b)
                <tr><td><a href="{{ route('budgets.show', $b) }}" class="fw-semibold">{{ $b->name }}</a></td><td><span class="app-num">{{ $b->year }}</span></td><td class="text-end"><span class="app-num">{{ $b->lines_count }}</span></td></tr>
            @empty
                <tr><td colspan="3" class="p-0"><x-empty icon="clipboard-data" :title="__('No budget yet.')" :text="__('Create one for the year, then enter each account\'s amount per month.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
