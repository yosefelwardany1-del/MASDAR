@extends('layouts.app')

@section('title', $product->code.' · '.$product->name)

@section('content')
<x-page-header :title="$product->name" :back="route('products.show', $product)" :back-label="$product->code.' · '.$product->name">
    <x-slot:meta><span class="app-doc-code">{{ $product->code }}</span></x-slot:meta>
</x-page-header>

<form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data" class="app-card app-docform app-docform--product">
    @csrf @method('PUT')
    @include('proforma-invoices._error-summary', ['prefix' => 'f-'])
    @include('products._form', ['removePhotoForm' => 'product-photo-remove'])
    <div class="app-docform__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('products.show', $product) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@if($product->photo)
    {{-- Its button is in the form's photo panel (form="product-photo-remove"): a form cannot hold another. --}}
    <form method="POST" action="{{ route('products.photo.destroy', $product) }}" id="product-photo-remove" class="d-none" onsubmit="return confirm(@js(__('Remove the photo?')));">
        @csrf @method('DELETE')
    </form>
@endif
@endsection
