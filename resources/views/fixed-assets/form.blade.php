@extends('layouts.app')

@php
    $title = $asset ? __('Edit :code', ['code' => $asset->code]) : __('Add an asset');
    $v = fn (string $f) => old($f, $asset ? ($asset->{$f} instanceof \DateTimeInterface ? $asset->{$f}->format('Y-m-d') : $asset->{$f}) : ($defaults[$f] ?? null));
    $plain = fn ($x) => $x === null ? '' : (rtrim(rtrim((string) $x, '0'), '.') ?: '0');
    $locked = $asset && $asset->depreciation()->exists();
@endphp

@section('title', $title)

@section('content')
<x-page-header :title="$title" :back="$asset ? route('fixed-assets.show', $asset) : route('fixed-assets.index')" :back-label="__('Fixed assets')" />
<form method="POST" action="{{ $asset ? route('fixed-assets.update', $asset) : route('fixed-assets.store') }}" class="app-form">
    @csrf @if($asset) @method('PUT') @endif
    @if($locked)<p class="app-note mb-3"><i class="bi bi-lock" aria-hidden="true"></i><span>{{ __('The asset is already depreciated: its cost, life, dates and accounts no longer change.') }}</span></p>@endif
    <x-section :title="__('The asset')" icon="pc-display">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="name">{{ __('Name') }}</label><input id="name" name="name" value="{{ $v('name') }}" class="form-control @error('name') is-invalid @enderror" required dir="auto">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-6"><label class="form-label" for="location">{{ __('Where it is') }}</label><input id="location" name="location" value="{{ $v('location') }}" class="form-control" dir="auto"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="acquired_on">{{ __('Bought on') }}</label><input id="acquired_on" type="date" max="{{ now()->toDateString() }}" name="acquired_on" value="{{ $v('acquired_on') }}" class="form-control @error('acquired_on') is-invalid @enderror" required @readonly($locked)></div>
            <div class="col-6 col-md-3"><label class="form-label" for="cost">{{ __('Cost (:book)', ['book' => \App\Models\BusinessSetting::current()->book_currency]) }}</label><input id="cost" name="cost" inputmode="decimal" value="{{ $plain($v('cost')) }}" class="form-control @error('cost') is-invalid @enderror" required @readonly($locked)>@error('cost')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-6 col-md-3"><label class="form-label" for="residual_value">{{ __('Worth at the end') }}</label><input id="residual_value" name="residual_value" inputmode="decimal" value="{{ $plain($v('residual_value') ?? '0') }}" class="form-control @error('residual_value') is-invalid @enderror" @readonly($locked)>@error('residual_value')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-6 col-md-3"><label class="form-label" for="useful_life_months">{{ __('Life (months)') }}</label><input id="useful_life_months" name="useful_life_months" type="number" min="1" max="600" value="{{ $v('useful_life_months') ?? 36 }}" class="form-control @error('useful_life_months') is-invalid @enderror" required @readonly($locked)>@error('useful_life_months')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-3"><label class="form-label" for="depreciation_starts_on">{{ __('Depreciated from') }}</label><input id="depreciation_starts_on" type="date" name="depreciation_starts_on" value="{{ $v('depreciation_starts_on') }}" class="form-control @error('depreciation_starts_on') is-invalid @enderror" required @readonly($locked)><div class="form-text">{{ __('From the first of that month.') }}</div></div>
            @foreach(['asset_account_id' => [__('Asset account'), $assetAccounts], 'depreciation_account_id' => [__('Accumulated depreciation account'), $contraAccounts], 'expense_account_id' => [__('Depreciation expense account'), $expenseAccounts]] as $field => [$label, $options])
                <div class="col-md-3"><label class="form-label" for="{{ $field }}">{{ $label }}</label>
                    <select id="{{ $field }}" name="{{ $field }}" class="form-select @error($field) is-invalid @enderror" @if($locked) style="pointer-events: none" tabindex="-1" @endif>
                        @foreach($options as $o)<option value="{{ $o->id }}" @selected((int) $v($field) === $o->id)>{{ $o->label() }}</option>@endforeach
                    </select>@error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            @endforeach
            <div class="col-12"><label class="form-label" for="notes">{{ __('Notes') }}</label><textarea id="notes" name="notes" rows="2" class="form-control" dir="auto">{{ $v('notes') }}</textarea></div>
        </div>
    </x-section>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button></div>
</form>
@endsection
