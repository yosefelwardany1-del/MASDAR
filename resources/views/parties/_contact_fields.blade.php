{{-- The fields of one contact (in this order: who, how to reach them, notes). Params: $contact (or null), $bag (its error bag), $useOld
     (whether the old input belongs to this form), $idPrefix (unique per form). --}}
@php
    $val = fn (string $field) => $useOld ? old($field) : $contact?->{$field};
    $invalid = fn (string $field) => $bag->has($field) ? 'is-invalid' : '';
@endphp
<div class="row g-2">
    <div class="col-md-4">
        <label class="form-label" for="{{ $idPrefix }}-name">{{ __('Name') }} <span class="text-danger" aria-hidden="true">*</span></label>
        <input type="text" name="name" id="{{ $idPrefix }}-name" class="form-control {{ $invalid('name') }}" value="{{ $val('name') }}" maxlength="160" required autocomplete="off">
        @if($bag->has('name'))<div class="invalid-feedback">{{ $bag->first('name') }}</div>@endif
    </div>
    <div class="col-md-4">
        <label class="form-label" for="{{ $idPrefix }}-role">{{ __('Role') }}</label>
        <input type="text" name="role" id="{{ $idPrefix }}-role" class="form-control {{ $invalid('role') }}" value="{{ $val('role') }}" maxlength="120">
    </div>
    <div class="col-md-4">
        <label class="form-label" for="{{ $idPrefix }}-email">{{ __('E-mail') }}</label>
        <input type="email" name="email" id="{{ $idPrefix }}-email" dir="ltr" class="form-control {{ $invalid('email') }}" value="{{ $val('email') }}" maxlength="160" autocomplete="off">
        @if($bag->has('email'))<div class="invalid-feedback">{{ $bag->first('email') }}</div>@endif
    </div>
    <div class="col-md-4">
        <label class="form-label" for="{{ $idPrefix }}-phone">{{ __('Phone') }}</label>
        <input type="tel" name="phone" id="{{ $idPrefix }}-phone" dir="ltr" class="form-control {{ $invalid('phone') }}" value="{{ $val('phone') }}" maxlength="40" placeholder="+86 …">
        @if($bag->has('phone'))<div class="invalid-feedback">{{ $bag->first('phone') }}</div>@endif
    </div>
    <div class="col-md-4">
        <label class="form-label" for="{{ $idPrefix }}-whatsapp">{{ __('WhatsApp') }}</label>
        <input type="tel" name="whatsapp" id="{{ $idPrefix }}-whatsapp" dir="ltr" class="form-control {{ $invalid('whatsapp') }}" value="{{ $val('whatsapp') }}" maxlength="40" placeholder="+971 …">
        @if($bag->has('whatsapp'))<div class="invalid-feedback">{{ $bag->first('whatsapp') }}</div>@endif
    </div>
    <div class="col-md-4">
        <label class="form-label" for="{{ $idPrefix }}-wechat">{{ __('WeChat ID') }}</label>
        <input type="text" name="wechat" id="{{ $idPrefix }}-wechat" dir="ltr" class="form-control {{ $invalid('wechat') }}" value="{{ $val('wechat') }}" maxlength="80">
    </div>
    <div class="col-md-8">
        <label class="form-label" for="{{ $idPrefix }}-notes">{{ __('Notes') }}</label>
        <input type="text" name="notes" id="{{ $idPrefix }}-notes" class="form-control {{ $invalid('notes') }}" value="{{ $val('notes') }}" maxlength="2000">
    </div>
    <div class="col-md-4 d-flex align-items-end pb-1">
        <input type="hidden" name="is_primary" value="0">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="{{ $idPrefix }}-primary" @checked($val('is_primary'))>
            <label class="form-check-label" for="{{ $idPrefix }}-primary">{{ __('Primary contact') }}</label>
        </div>
    </div>
</div>
