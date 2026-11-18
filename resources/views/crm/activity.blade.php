@extends('layouts.app')

@section('title', __('Activity'))

@php
    $channelIcons = ['call' => 'telephone', 'whatsapp' => 'whatsapp', 'wechat' => 'wechat', 'email' => 'envelope', 'meeting' => 'people', 'factory_visit' => 'building'];
    $iErrors = $errors->getBag('interaction');
@endphp

@section('content')
<x-page-header :title="__('Activity')" :subtitle="__('Every call, message, meeting and visit with clients, suppliers and shipping companies, newest first.')" />

@include('crm._nav', ['active' => 'activity'])

<div class="row g-3">
    <div class="{{ Gate::allows('crm.edit') ? 'col-xl-8' : 'col-12' }}">
        <form method="GET" class="app-card app-crm-filter mb-3" role="search">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="f-user">{{ __('Person') }}</label>
                    <select name="user" id="f-user" class="form-select form-select-sm">
                        <option value="">{{ __('Everyone') }}</option>
                        @foreach($people as $p)<option value="{{ $p->id }}" @selected($filters['user'] === $p->id)>{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="f-channel">{{ __('Channel') }}</label>
                    <select name="channel" id="f-channel" class="form-select form-select-sm">
                        <option value="">{{ __('All') }}</option>
                        @foreach($channels as $code => $name)<option value="{{ $code }}" @selected($filters['channel'] === $code)>{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="f-kind">{{ __('With') }}</label>
                    <select name="kind" id="f-kind" class="form-select form-select-sm">
                        <option value="">{{ __('Anyone') }}</option>
                        <option value="client" @selected($filters['kind'] === 'client')>{{ __('Clients') }}</option>
                        <option value="supplier" @selected($filters['kind'] === 'supplier')>{{ __('Suppliers') }}</option>
                        <option value="shipping" @selected($filters['kind'] === 'shipping')>{{ __('Shipping companies') }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="f-from">{{ __('From') }}</label>
                    <input type="date" name="from" id="f-from" value="{{ $filters['from'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small" for="f-to">{{ __('To') }}</label>
                    <input type="date" name="to" id="f-to" value="{{ $filters['to'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-12 d-flex gap-2 justify-content-md-end">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Filter') }}</button>
                    <a href="{{ route('crm.activity') }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>
                </div>
            </div>
        </form>
        <x-section :title="__('Contacts')" icon="chat-left-text" :subtitle="trans_choice('{0} none|{1} one|[2,*] :count', $interactions->total(), ['count' => $interactions->total()])" flush>
            @include('crm._timeline', ['interactions' => $interactions, 'channelIcons' => $channelIcons, 'channels' => $channels, 'compact' => false])
        </x-section>
        <div class="mt-3">{{ $interactions->links() }}</div>
    </div>
    @can('crm.edit')
        <div class="col-xl-4">
            <x-section :title="__('Log a contact')" icon="journal-plus" id="log">
                <form method="POST" action="{{ route('crm.activity.store') }}" class="app-form">
                    @csrf
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="l-party">{{ __('With') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <select name="party_id" id="l-party" class="form-select {{ $iErrors->has('party_id') ? 'is-invalid' : '' }}" required>
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach($parties as $p)<option value="{{ $p->id }}" @selected((int) old('party_id') === $p->id)>{{ $p->name }} · {{ $p->code }}</option>@endforeach
                            </select>
                            @if($iErrors->has('party_id'))<div class="invalid-feedback">{{ $iErrors->first('party_id') }}</div>@endif
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="l-channel">{{ __('Channel') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <select name="channel" id="l-channel" class="form-select" required>
                                @foreach($channels as $code => $name)<option value="{{ $code }}" @selected(old('channel') === $code)>{{ $name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="l-when">{{ __('When') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="datetime-local" name="happened_at" id="l-when" class="form-control {{ $iErrors->has('happened_at') ? 'is-invalid' : '' }}" value="{{ old('happened_at', now()->format('Y-m-d\TH:i')) }}" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="l-summary">{{ __('What was said') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <textarea name="summary" id="l-summary" rows="3" dir="auto" class="form-control {{ $iErrors->has('summary') ? 'is-invalid' : '' }}" required>{{ old('summary') }}</textarea>
                            @if($iErrors->has('summary'))<div class="invalid-feedback">{{ $iErrors->first('summary') }}</div>@endif
                        </div>
                        <div class="col-7">
                            <label class="form-label" for="l-next">{{ __('Next step') }}</label>
                            <input name="next_step" id="l-next" dir="auto" class="form-control" value="{{ old('next_step') }}">
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="l-due">{{ __('Next step due') }}</label>
                            <input type="date" name="next_step_due" id="l-due" class="form-control {{ $iErrors->has('next_step_due') ? 'is-invalid' : '' }}" value="{{ old('next_step_due') }}">
                            @if($iErrors->has('next_step_due'))<div class="invalid-feedback">{{ $iErrors->first('next_step_due') }}</div>@endif
                        </div>
                        <div class="col-12 form-text mt-1">{{ __('A next step with a due date also becomes a task for you.') }}</div>
                        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-journal-plus" aria-hidden="true"></i>{{ __('Record interaction') }}</button></div>
                    </div>
                </form>
            </x-section>
        </div>
    @endcan
</div>
@endsection
