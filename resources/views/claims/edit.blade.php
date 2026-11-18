@extends('layouts.app')

@section('title', __('Edit claim :number', ['number' => $claim->number]))

@section('content')
<x-page-header :title="__('Edit claim :number', ['number' => $claim->number])" :back="route('claims.show', $claim)" :back-label="$claim->number" />
<form method="POST" action="{{ route('claims.update', $claim) }}" class="app-form">
    @csrf @method('PUT')
    @error('claim')<div class="alert alert-danger">{{ $message }}</div>@enderror
    <x-section :title="__('The claim')" icon="exclamation-octagon">
        @include('claims._form')
    </x-section>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button></div>
</form>
@endsection
