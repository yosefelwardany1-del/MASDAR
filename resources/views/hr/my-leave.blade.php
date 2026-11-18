@extends('layouts.app')

@section('title', __('My leave'))

@section('content')
<x-page-header :title="__('My leave')" :subtitle="__('What you have left this year, your requests, and a new request.')" />

@if(! $employee)
    <div class="app-card">
        <x-empty icon="person-x" :title="__('Your account is not linked to an employee file.')" :text="__('Ask HR to link your user account to your employee file; then you can request leave here.')" />
    </div>
@else
    <div class="row g-3">
        <div class="col-xl-7">
            <x-section :title="__('My balances')" icon="pie-chart" :subtitle="(string) $year" flush class="mb-3">
                @include('hr._balances', ['balances' => $balances])
            </x-section>
            <x-section :title="__('My requests')" icon="calendar-check" flush>
                @include('hr._requests', ['requests' => $requests, 'showEmployee' => false, 'selfService' => true])
            </x-section>
        </div>
        <div class="col-xl-5">
            <x-section :title="__('Request leave')" icon="send">
                @include('hr._leave-form', ['action' => route('my-leave.store'), 'types' => $types, 'employees' => null])
                <p class="app-note mt-3 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('A manager decides; you are told here and in your notifications.') }}</span></p>
            </x-section>
        </div>
    </div>
@endif
@endsection
