{{-- Shared by create and edit. $shipment is null on create. --}}
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">{{ __('Shipping company') }} <span class="text-danger">*</span></label>
        <select name="shipping_company_id" class="form-select @error('shipping_company_id') is-invalid @enderror" required>
            <option value="">{{ __('Choose…') }}</option>
            @foreach($companies as $company)
                <option value="{{ $company->id }}" @selected((string) old('shipping_company_id', $shipment?->shipping_company_id) === (string) $company->id)>{{ $company->name }}</option>
            @endforeach
        </select>
        @error('shipping_company_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Shipping mode') }}</label>
        <select name="mode" class="form-select @error('mode') is-invalid @enderror">
            <option value="">—</option>
            @foreach($modes as $code => $name)
                <option value="{{ $code }}" @selected((string) old('mode', $shipment?->mode) === (string) $code)>{{ $name }}</option>
            @endforeach
        </select>
        @error('mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label">{{ __('Booking no.') }}</label>
        <input type="text" name="booking_no" dir="ltr" class="form-control @error('booking_no') is-invalid @enderror" value="{{ old('booking_no', $shipment?->booking_no) }}" maxlength="80">
        @error('booking_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label">{{ __('Port of loading') }}</label>
        <select name="port_of_loading" class="form-select @error('port_of_loading') is-invalid @enderror">
            <option value="">—</option>
            @foreach($loadingPorts as $code => $name)
                <option value="{{ $code }}" @selected((string) old('port_of_loading', $shipment?->port_of_loading) === (string) $code)>{{ $name }}</option>
            @endforeach
        </select>
        @error('port_of_loading')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label">{{ __('Port of discharge') }}</label>
        <select name="port_of_discharge" class="form-select @error('port_of_discharge') is-invalid @enderror">
            <option value="">—</option>
            @foreach($dischargePorts as $code => $name)
                <option value="{{ $code }}" @selected((string) old('port_of_discharge', $shipment?->port_of_discharge) === (string) $code)>{{ $name }}</option>
            @endforeach
        </select>
        @error('port_of_discharge')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('ETD') }}</label>
        <input type="date" name="etd" class="form-control @error('etd') is-invalid @enderror" value="{{ old('etd', $shipment?->etd?->toDateString()) }}">
        <div class="form-text">{{ __('Estimated time of departure') }}</div>
        @error('etd')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('ETA') }}</label>
        <input type="date" name="eta" class="form-control @error('eta') is-invalid @enderror" value="{{ old('eta', $shipment?->eta?->toDateString()) }}">
        <div class="form-text">{{ __('Estimated time of arrival') }}</div>
        @error('eta')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Vessel') }}</label>
        <input type="text" name="vessel" dir="ltr" class="form-control @error('vessel') is-invalid @enderror" value="{{ old('vessel', $shipment?->vessel) }}" maxlength="120">
        @error('vessel')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Voyage') }}</label>
        <input type="text" name="voyage" dir="ltr" class="form-control @error('voyage') is-invalid @enderror" value="{{ old('voyage', $shipment?->voyage) }}" maxlength="60">
        @error('voyage')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('B/L no.') }}</label>
        <input type="text" name="bl_number" dir="ltr" class="form-control @error('bl_number') is-invalid @enderror" value="{{ old('bl_number', $shipment?->bl_number) }}" maxlength="80">
        @error('bl_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label class="form-label">{{ __('Notes') }}</label>
        <textarea name="notes" rows="3" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $shipment?->notes) }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
