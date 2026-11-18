{{-- Shared by create and edit. $shipment is null on create. Fields in three groups: who carries it, the voyage, notes. --}}
@php
    $field = fn (string $name) => $errors->has($name) ? 'is-invalid' : '';
@endphp
<x-section :title="__('Carrier and booking')" icon="building">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="sh-company">{{ __('Shipping company') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
            <select id="sh-company" name="shipping_company_id" class="form-select {{ $field('shipping_company_id') }}" required>
                <option value="">{{ __('Choose…') }}</option>
                @foreach($companies as $company)
                    <option value="{{ $company->id }}" @selected((string) old('shipping_company_id', $shipment?->shipping_company_id) === (string) $company->id)>{{ $company->name }}</option>
                @endforeach
            </select>
            @error('shipping_company_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="sh-mode">{{ __('Shipping mode') }}</label>
            <select id="sh-mode" name="mode" class="form-select {{ $field('mode') }}">
                <option value="">—</option>
                @foreach($modes as $code => $name)
                    <option value="{{ $code }}" @selected((string) old('mode', $shipment?->mode) === (string) $code)>{{ $name }}</option>
                @endforeach
            </select>
            @error('mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="sh-booking">{{ __('Booking no.') }}</label>
            <input id="sh-booking" type="text" name="booking_no" dir="ltr" class="form-control {{ $field('booking_no') }}" value="{{ old('booking_no', $shipment?->booking_no) }}" maxlength="80">
            @error('booking_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="sh-bl">{{ __('B/L no.') }}</label>
            <input id="sh-bl" type="text" name="bl_number" dir="ltr" class="form-control {{ $field('bl_number') }}" value="{{ old('bl_number', $shipment?->bl_number) }}" maxlength="80">
            @error('bl_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="sh-incoterm">{{ __('Incoterm') }}</label>
            <input id="sh-incoterm" type="text" name="incoterm" dir="ltr" class="form-control {{ $field('incoterm') }}" value="{{ old('incoterm', $shipment?->incoterm) }}" maxlength="60" placeholder="{{ \App\Models\BusinessSetting::current()->default_incoterm }} Ningbo" aria-describedby="sh-incoterm-help">
            @error('incoterm')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text" id="sh-incoterm-help">{{ __('Printed on the commercial invoice. Empty: the business default.') }}</div>
        </div>
    </div>
</x-section>

<x-section :title="__('Route and schedule')" icon="signpost-split">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="sh-pol">{{ __('Port of loading') }}</label>
            <select id="sh-pol" name="port_of_loading" class="form-select {{ $field('port_of_loading') }}">
                <option value="">—</option>
                @foreach($loadingPorts as $code => $name)
                    <option value="{{ $code }}" @selected((string) old('port_of_loading', $shipment?->port_of_loading) === (string) $code)>{{ $name }}</option>
                @endforeach
            </select>
            @error('port_of_loading')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="sh-pod">{{ __('Port of discharge') }}</label>
            <select id="sh-pod" name="port_of_discharge" class="form-select {{ $field('port_of_discharge') }}">
                <option value="">—</option>
                @foreach($dischargePorts as $code => $name)
                    <option value="{{ $code }}" @selected((string) old('port_of_discharge', $shipment?->port_of_discharge) === (string) $code)>{{ $name }}</option>
                @endforeach
            </select>
            @error('port_of_discharge')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="sh-etd">{{ __('ETD') }}</label>
            <input id="sh-etd" type="date" name="etd" class="form-control {{ $field('etd') }}" value="{{ old('etd', $shipment?->etd?->toDateString()) }}" aria-describedby="sh-etd-help">
            <div class="form-text" id="sh-etd-help">{{ __('Estimated time of departure') }}</div>
            @error('etd')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="sh-eta">{{ __('ETA') }}</label>
            <input id="sh-eta" type="date" name="eta" class="form-control {{ $field('eta') }}" value="{{ old('eta', $shipment?->eta?->toDateString()) }}" aria-describedby="sh-eta-help">
            <div class="form-text" id="sh-eta-help">{{ __('Estimated time of arrival') }}</div>
            @error('eta')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="sh-vessel">{{ __('Vessel') }}</label>
            <input id="sh-vessel" type="text" name="vessel" dir="ltr" class="form-control {{ $field('vessel') }}" value="{{ old('vessel', $shipment?->vessel) }}" maxlength="120">
            @error('vessel')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="sh-voyage">{{ __('Voyage') }}</label>
            <input id="sh-voyage" type="text" name="voyage" dir="ltr" class="form-control {{ $field('voyage') }}" value="{{ old('voyage', $shipment?->voyage) }}" maxlength="60">
            @error('voyage')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</x-section>

<x-section :title="__('Notes')" icon="chat-left-text">
    <label class="form-label visually-hidden" for="sh-notes">{{ __('Notes') }}</label>
    <textarea id="sh-notes" name="notes" rows="3" class="form-control {{ $field('notes') }}">{{ old('notes', $shipment?->notes) }}</textarea>
    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
</x-section>
