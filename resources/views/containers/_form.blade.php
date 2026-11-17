{{-- A container's own fields. $container is null when adding one; $typeOptions code => name; optional $idPrefix to keep ids unique. --}}
@php $p = $idPrefix ?? 'ct'; @endphp
<div class="row g-3">
    <div class="col-sm-6 col-lg-3">
        <label class="form-label" for="{{ $p }}-no">{{ __('Container no.') }}</label>
        <input id="{{ $p }}-no" type="text" name="container_no" dir="ltr" class="form-control @error('container_no') is-invalid @enderror" value="{{ old('container_no', $container?->container_no) }}" maxlength="20" placeholder="MSKU1234567" aria-describedby="{{ $p }}-no-help">
        <div class="form-text" id="{{ $p }}-no-help">{{ __('Letters and digits only; spaces are removed.') }}</div>
        @error('container_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-sm-6 col-lg-3">
        <label class="form-label" for="{{ $p }}-type">{{ __('Container type') }}</label>
        <select id="{{ $p }}-type" name="container_type" class="form-select @error('container_type') is-invalid @enderror">
            <option value="">—</option>
            @foreach($typeOptions as $code => $name)
                <option value="{{ $code }}" @selected((string) old('container_type', $container?->container_type) === (string) $code)>{{ $name }}</option>
            @endforeach
        </select>
        @error('container_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-sm-6 col-lg-3">
        <label class="form-label" for="{{ $p }}-seal">{{ __('Seal no.') }}</label>
        <input id="{{ $p }}-seal" type="text" name="seal_no" dir="ltr" class="form-control @error('seal_no') is-invalid @enderror" value="{{ old('seal_no', $container?->seal_no) }}" maxlength="40">
        @error('seal_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-sm-6 col-lg-3">
        <label class="form-label" for="{{ $p }}-notes">{{ __('Notes') }}</label>
        <input id="{{ $p }}-notes" type="text" name="notes" class="form-control @error('notes') is-invalid @enderror" value="{{ old('notes', $container?->notes) }}">
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
