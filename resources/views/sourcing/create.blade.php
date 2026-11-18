@extends('layouts.app')

@section('title', __('New request'))

@section('content')
<x-page-header :title="__('New request')" :back="route('sourcing.index')" :back-label="__('Factory quotes')" :subtitle="__('What the client wants; the factories\' quotes are added on the next page.')" />
<form method="POST" action="{{ route('sourcing.store') }}" class="app-form">
    @csrf
    <x-section :title="__('The request')" icon="ui-checks-grid">@include('sourcing._form')</x-section>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Create the request') }}</button></div>
</form>
@endsection
