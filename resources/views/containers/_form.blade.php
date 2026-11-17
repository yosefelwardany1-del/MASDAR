{{-- A container's own fields. $container is null when adding one; $typeOptions code => name. --}}
<div class="row g-3">
    <div class="col-md-3">
        <label class="form-label">{{ __('Container no.') }}</label>
        <input type="text" name="container_no" dir="ltr" class="form-control @error('container_no') is-invalid @enderror" value="{{ old('container_no', $container?->container_no) }}" maxlength="20" placeholder="MSKU1234567">
        @error('container_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Container type') }}</label>
        <select name="container_type" class="form-select @error('container_type') is-invalid @enderror">
            <option value="">—</option>
            @foreach($typeOptions as $code => $name)
                <option value="{{ $code }}" @selected((string) old('container_type', $container?->container_type) === (string) $code)>{{ $name }}</option>
            @endforeach
        </select>
        @error('container_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Seal no.') }}</label>
        <input type="text" name="seal_no" dir="ltr" class="form-control @error('seal_no') is-invalid @enderror" value="{{ old('seal_no', $container?->seal_no) }}" maxlength="40">
        @error('seal_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Notes') }}</label>
        <input type="text" name="notes" class="form-control @error('notes') is-invalid @enderror" value="{{ old('notes', $container?->notes) }}">
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
