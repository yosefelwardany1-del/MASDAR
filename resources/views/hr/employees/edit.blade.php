@extends('layouts.app')

@section('title', __('Edit').' · '.$employee->name)

@section('content')
<x-page-header :title="$employee->name" :subtitle="__('The code :code never changes.', ['code' => $employee->code])"
               :back="route('employees.show', $employee)" :back-label="__('Back')" />

<form method="POST" action="{{ route('employees.update', $employee) }}" class="app-card app-docform">
    @csrf @method('PUT')
    @include('hr.employees._form')
    <div class="app-docform__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('employees.show', $employee) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
