@extends('layouts.app')

@section('title', __('New sample'))

@section('content')
<x-page-header :title="__('New sample')" :back="route('samples.index')" :back-label="__('Samples')" />
<form method="POST" action="{{ route('samples.store') }}" class="app-form">
    @csrf
    <x-section :title="__('The sample')" icon="gift">
        <div class="row g-3">
            <div class="col-12"><label class="form-label" for="description">{{ __('What sample') }}</label><textarea id="description" name="description" rows="2" class="form-control @error('description') is-invalid @enderror" required dir="auto">{{ old('description') }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-6"><label class="form-label" for="client_id">{{ __('For the client') }}</label><select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror"><option value="">—</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id') === $c->id)>{{ $c->name }} ({{ $c->code }})</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label" for="proforma_invoice_id">{{ __('For the PI (optional)') }}</label><select id="proforma_invoice_id" name="proforma_invoice_id" class="form-select"><option value="">—</option>@foreach($pis as $p)<option value="{{ $p->id }}">{{ $p->reference() }}</option>@endforeach</select></div>
            @if($suppliers->isNotEmpty())
                <div class="col-md-6"><label class="form-label" for="supplier_id">{{ __('From the factory') }}</label><select id="supplier_id" name="supplier_id" class="form-select"><option value="">—</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id') === $s->id)>{{ $s->name }} ({{ $s->code }})</option>@endforeach</select></div>
            @endif
            <div class="col-md-6"><label class="form-label" for="product_id">{{ __('Product') }}</label><select id="product_id" name="product_id" class="form-select"><option value="">—</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->code }})</option>@endforeach</select></div>
            <div class="col-4"><label class="form-label" for="quantity">{{ __('Quantity') }}</label><input id="quantity" name="quantity" inputmode="decimal" value="{{ old('quantity') }}" class="form-control @error('quantity') is-invalid @enderror">@error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-4"><label class="form-label" for="requested_on">{{ __('Requested on') }}</label><input id="requested_on" type="date" name="requested_on" value="{{ old('requested_on', now()->toDateString()) }}" class="form-control"></div>
            <div class="col-4"><label class="form-label" for="needed_by">{{ __('Needed by') }}</label><input id="needed_by" type="date" name="needed_by" value="{{ old('needed_by') }}" class="form-control @error('needed_by') is-invalid @enderror">@error('needed_by')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        </div>
    </x-section>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button></div>
</form>
@endsection
