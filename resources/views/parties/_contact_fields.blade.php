{{-- The fields of one contact. Params: $contact (or null), $bag (its error bag), $useOld
     (whether the old input belongs to this form), $idPrefix (unique per form). --}}
@php
    $val = fn (string $field) => $useOld ? old($field) : $contact?->{$field};
    $invalid = fn (string $field) => $bag->has($field) ? 'is-invalid' : '';
@endphp
<div class="row g-2">
    <div class="col-md-4">
        <label class="form-label small" for="{{ $idPrefix }}-name">{{ __('Name') }} <span class="text-danger">*</span></label>
        <input type="text" name="name" id="{{ $idPrefix }}-name" class="form-control form-control-sm {{ $invalid('name') }}" value="{{ $val('name') }}" maxlength="160" required>
        @if($bag->has('name'))<div class="invalid-feedback">{{ $bag->first('name') }}</div>@endif
    </div>
    <div class="col-md-4">
        <label class="form-label small" for="{{ $idPrefix }}-role">{{ __('Role') }}</label>
        <input type="text" name="role" id="{{ $idPrefix }}-role" class="form-control form-control-sm {{ $invalid('role') }}" value="{{ $val('role') }}" maxlength="120">
    </div>
    <div class="col-md-4">
        <label class="form-label small" for="{{ $idPrefix }}-email">{{ __('E-mail') }}</label>
        <input type="email" name="email" id="{{ $idPrefix }}-email" dir="ltr" class="form-control form-control-sm {{ $invalid('email') }}" value="{{ $val('email') }}" maxlength="160">
        @if($bag->has('email'))<div class="invalid-feedback">{{ $bag->first('email') }}</div>@endif
    </div>
    <div class="col-md-4">
        <label class="form-label small" for="{{ $idPrefix }}-phone">{{ __('Phone') }}</label>
        <input type="tel" name="phone" id="{{ $idPrefix }}-phone" dir="ltr" class="form-control form-control-sm {{ $invalid('phone') }}" value="{{ $val('phone') }}" maxlength="40" placeholder="+86 …">
        @if($bag->has('phone'))<div class="invalid-feedback">{{ $bag->first('phone') }}</div>@endif
    </div>
    <div class="col-md-4">
        <label class="form-label small" for="{{ $idPrefix }}-whatsapp">{{ __('WhatsApp') }}</label>
        <input type="tel" name="whatsapp" id="{{ $idPrefix }}-whatsapp" dir="ltr" class="form-control form-control-sm {{ $invalid('whatsapp') }}" value="{{ $val('whatsapp') }}" maxlength="40" placeholder="+971 …">
        @if($bag->has('whatsapp'))<div class="invalid-feedback">{{ $bag->first('whatsapp') }}</div>@endif
    </div>
    <div class="col-md-4">
        <label class="form-label small" for="{{ $idPrefix }}-wechat">{{ __('WeChat ID') }}</label>
        <input type="text" name="wechat" id="{{ $idPrefix }}-wechat" dir="ltr" class="form-control form-control-sm {{ $invalid('wechat') }}" value="{{ $val('wechat') }}" maxlength="80">
    </div>
    <div class="col-md-8">
        <label class="form-label small" for="{{ $idPrefix }}-notes">{{ __('Notes') }}</label>
        <input type="text" name="notes" id="{{ $idPrefix }}-notes" class="form-control form-control-sm {{ $invalid('notes') }}" value="{{ $val('notes') }}" maxlength="2000">
    </div>
    <div class="col-md-4 d-flex align-items-end">
        <input type="hidden" name="is_primary" value="0">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="{{ $idPrefix }}-primary" @checked($val('is_primary'))>
            <label class="form-check-label small" for="{{ $idPrefix }}-primary">{{ __('Primary contact') }}</label>
        </div>
    </div>
</div>
