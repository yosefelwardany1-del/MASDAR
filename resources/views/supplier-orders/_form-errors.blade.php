{{-- The error summary at the top of a form page (follow-up area): focusable, read out when it appears;
     each field keeps its own message beside it too. --}}
@if($errors->any())
    <div class="alert alert-danger app-fu-errors" role="alert" tabindex="-1" id="form-errors">
        <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
        <div>
            <div class="app-fu-errors__title">{{ __('Some details need fixing') }}</div>
            <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    </div>
@endif
