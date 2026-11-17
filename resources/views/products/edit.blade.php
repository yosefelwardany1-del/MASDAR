@extends('layouts.app')

@section('title', $product->code.' · '.$product->name)

@section('content')
<h1 class="h4 mb-3"><span class="app-num text-muted me-2">{{ $product->code }}</span>{{ $product->name }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        @include('products._form')
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route('products.show', $product) }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
    @if($product->photo)
        <form method="POST" action="{{ route('products.photo.destroy', $product) }}" class="mt-3" onsubmit="return confirm(@js(__('Remove the photo?')));">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-image me-1"></i>{{ __('Remove photo') }}</button>
        </form>
    @endif
</div>
@endsection
