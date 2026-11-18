@extends('layouts.app')

@section('title', __('Open a claim'))

@section('content')
<x-page-header :title="__('Open a claim')" :back="route('claims.index')" :back-label="__('Claims')"
               :subtitle="__('Name the purchase order the goods came on, the client\'s PI, or both. Photos are added on the claim\'s page.')" />
<form method="POST" action="{{ route('claims.store') }}" class="app-form">
    @csrf
    <x-section :title="__('The claim')" icon="exclamation-octagon">
        @include('claims._form')
    </x-section>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Open the claim') }}</button></div>
</form>
@endsection
