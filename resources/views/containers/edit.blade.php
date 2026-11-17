@extends('layouts.app')

@section('title', __('Edit container'))

@section('content')
<h1 class="h4 mb-3">{{ __('Container') }} <span class="app-ltr">{{ $container->container_no ?: '#'.$container->id }}</span>
    <span class="text-muted fs-6">· <span class="app-ltr">{{ $container->shipment->number }}</span></span></h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('containers.update', $container) }}">
        @csrf @method('PUT')
        @include('containers._form', ['container' => $container])
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route('containers.show', $container) }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
