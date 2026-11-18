@extends('layouts.app')

@section('title', __('New employee'))

@section('content')
<x-page-header :title="__('New employee')" :subtitle="__('A code is given when it is saved. Salary, documents and leave are added on their page.')"
               :back="route('employees.index')" :back-label="__('Employees')" />

<form method="POST" action="{{ route('employees.store') }}" class="app-card app-docform">
    @csrf
    @include('hr.employees._form', ['employee' => null])
    <div class="app-docform__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Create') }}</button>
        <a href="{{ route('employees.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
