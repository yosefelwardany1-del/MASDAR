@extends('layouts.app')

@php $title = $kind === 'credit' ? __('New credit note') : __('New debit note'); @endphp

@section('title', $title)

@section('content')
<x-page-header :title="$title" :back="route('trade-notes.index', ['kind' => $kind])" :back-label="__('Claims & notes')"
               :subtitle="__('Saved as a draft: check it, then a manager issues it. An issued note never changes.')" />
<form method="POST" action="{{ route('trade-notes.store') }}" class="app-form">
    @csrf
    <input type="hidden" name="kind" value="{{ $kind }}">
    @error('note')<div class="alert alert-danger">{{ $message }}</div>@enderror
    <x-section :title="__('The note')" icon="arrow-left-right">
        @include('trade-notes._header-form', ['note' => null])
    </x-section>
    <x-section :title="__('First line')" icon="list-ul" class="mt-3">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label" for="line_description">{{ __('Description') }}</label>
                <input id="line_description" name="line_description" value="{{ old('line_description', $preset['reason'] ?? '') }}" class="form-control @error('description') is-invalid @enderror" dir="auto">
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="line_amount">{{ __('Amount') }}</label>
                <input id="line_amount" name="line_amount" inputmode="decimal" value="{{ old('line_amount', filled($preset['amount'] ?? null) ? rtrim(rtrim((string) $preset['amount'], '0'), '.') : '') }}" class="form-control @error('unit_price') is-invalid @enderror">
                @error('unit_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="form-text mt-2">{{ __('More lines can be added on the next page.') }}</div>
    </x-section>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save the draft') }}</button></div>
</form>
@endsection
