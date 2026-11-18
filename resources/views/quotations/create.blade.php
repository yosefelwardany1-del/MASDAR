@extends('layouts.app')

@section('title', __('New quotation'))

@section('content')
<x-page-header :title="__('New quotation')" :subtitle="__('The header first; the lines are added on the next page. A number is given when it is sent.')"
               :back="route('quotations.index')" :back-label="__('Quotations')" />

<form method="POST" action="{{ route('quotations.store') }}" class="app-form">
    @csrf
    <x-section :title="__('Quotation')" icon="chat-square-quote">
        @include('quotations._header-form', ['quotation' => null])
    </x-section>
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Create') }}</button>
        <a href="{{ route('quotations.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
