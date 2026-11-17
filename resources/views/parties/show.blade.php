@extends('layouts.app')

@section('title', $party->code.' · '.$party->name)

@php
    use App\Models\BusinessSetting;
    $prefix = $kind->routePrefix();
    $screen = $kind->screen();
    $label = fn (string $list, ?string $code) => $code === null || $code === '' ? '—' : ($labels[$list][$code] ?? $code);
    $settings = BusinessSetting::current();
    $deposit = $party->deposit_percent ?? $settings->default_deposit_percent;
@endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><span class="app-num text-muted me-2">{{ $party->code }}</span>{{ $party->name }}</h1>
        <div class="d-flex flex-wrap gap-1 align-items-center">
            @if($party->name_local)<span class="text-muted me-2">{{ $party->name_local }}</span>@endif
            @foreach($party->kinds() as $k)<span class="app-pill">{{ __($k->label()) }}</span>@endforeach
            @if($party->active)<span class="app-pill app-pill--done">{{ __('Active') }}</span>@else<span class="app-pill app-pill--skip">{{ __('Inactive') }}</span>@endif
        </div>
    </div>
    @can($screen.'.edit')
        <div class="d-flex gap-2">
            <a href="{{ route($prefix.'.edit', $party) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit') }}</a>
            @if($party->active)
                <form method="POST" action="{{ route($prefix.'.deactivate', $party) }}" onsubmit="return confirm(@js(__('Deactivate this party? It stays on every document that refers to it.')));">
                    @csrf<button class="btn btn-light text-danger"><i class="bi bi-slash-circle me-1"></i>{{ __('Deactivate') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route($prefix.'.reactivate', $party) }}">
                    @csrf<button class="btn btn-light"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Reactivate') }}</button>
                </form>
            @endif
        </div>
    @endcan
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="app-card p-4">
            <h2 class="h6 mb-3">{{ __('Details') }}</h2>
            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Country') }}</dt><dd class="col-sm-8">{{ $label('country', $party->country) }}@if($party->city) · {{ $party->city }}@endif</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Address') }}</dt><dd class="col-sm-8">{!! $party->address ? nl2br(e($party->address)) : '—' !!}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Default currency') }}</dt><dd class="col-sm-8">{{ $label('currency', $party->currency) }}</dd>

                @if($party->is_client)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Account manager') }}</dt><dd class="col-sm-8">{{ $party->accountManager?->name ?? '—' }}</dd>
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('How they found us') }}</dt><dd class="col-sm-8">{{ $label('party_source', $party->source) }}</dd>
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Stage') }}</dt><dd class="col-sm-8">{{ $label('client_stage', $party->stage) }}</dd>
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Payment terms') }}</dt>
                    <dd class="col-sm-8"><span class="app-ltr">{{ $settings->piTerms($deposit) }}</span>@if($party->deposit_percent === null) <span class="text-muted small">({{ __('business default') }})</span>@endif</dd>
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Shipped to') }}</dt><dd class="col-sm-8">{{ $party->default_destination ?: '—' }}</dd>
                @endif

                @if($party->is_supplier)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Factory or market booth address') }}</dt><dd class="col-sm-8">{!! $party->factory_address ? nl2br(e($party->factory_address)) : '—' !!}</dd>
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('What they make') }}</dt><dd class="col-sm-8">{!! $party->products_note ? nl2br(e($party->products_note)) : '—' !!}</dd>
                    @if($canSeeBank)
                        <dt class="col-sm-4 text-muted fw-normal">{{ __('Bank or payment details') }}</dt><dd class="col-sm-8" dir="auto" data-test="bank-details">{!! $party->bank_details ? nl2br(e($party->bank_details)) : '—' !!}</dd>
                    @endif
                @endif

                @if($party->is_shipping)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Kind of shipping company') }}</dt><dd class="col-sm-8">{{ $label('shipping_company_kind', $party->shipping_kind) }}</dd>
                @endif

                <dt class="col-sm-4 text-muted fw-normal">{{ __('Notes') }}</dt><dd class="col-sm-8">{!! $party->notes ? nl2br(e($party->notes)) : '—' !!}</dd>
            </dl>
        </div>

        @include('parties._contacts')

        @include('parties._related')
    </div>

    <div class="col-lg-5">
        @include('parties._tasks')
        @include('parties._interactions')
    </div>
</div>

@include('partials.attachments', ['attachable' => $party, 'type' => 'parties'])
@endsection
