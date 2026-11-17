@extends('layouts.app')

@section('title', __('Lists & options'))

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Lists & options') }}</h1>
</div>
<p class="text-muted">{{ __('Every choice MASDAR has not settled yet lives here. Change it at any time: documents keep the codes they were made with.') }}</p>

<ul class="nav nav-tabs mb-3">
    @foreach(['options' => __('Business options'), 'lists' => __('Lists'), 'steps' => __('Order follow-up steps')] as $key => $label)
        <li class="nav-item"><a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('configuration.index', ['tab' => $key]) }}">{{ $label }}</a></li>
    @endforeach
</ul>

@if($tab === 'options')
    <form method="POST" action="{{ route('configuration.options.update') }}" class="app-card p-3">
        @csrf @method('PUT')
        <fieldset @disabled(! $editable)>
        <h2 class="h6 mb-3">{{ __('Proforma Invoice letterhead and terms') }}</h2>
        <div class="row g-3 mb-4">
            <div class="col-md-8">
                <label class="form-label" for="company_address">{{ __('Address on documents') }}</label>
                <input id="company_address" name="company_address" class="form-control" dir="ltr" value="{{ old('company_address', $setting->company_address) }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="company_phone">{{ __('Phone on documents') }}</label>
                <input id="company_phone" name="company_phone" class="form-control" dir="ltr" value="{{ old('company_phone', $setting->company_phone) }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="default_deposit_percent">{{ __('Default deposit %') }}</label>
                <input id="default_deposit_percent" name="default_deposit_percent" type="number" step="0.01" min="0" max="100" class="form-control @error('default_deposit_percent') is-invalid @enderror" value="{{ old('default_deposit_percent', $setting->default_deposit_percent) }}">
                @error('default_deposit_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-9">
                <label class="form-label" for="pi_terms_template">{{ __('Payment terms on the PI') }}</label>
                <input id="pi_terms_template" name="pi_terms_template" class="form-control" dir="ltr" value="{{ old('pi_terms_template', $setting->pi_terms_template) }}">
                <div class="form-text">{{ __('{deposit} and {final} are replaced by the PI\'s percentages.') }} <span class="app-ltr">{{ $setting->piTerms($setting->default_deposit_percent) }}</span></div>
            </div>
        </div>

        <h2 class="h6 mb-3">{{ __('Currencies') }}</h2>
        <div class="row g-3 mb-4">
            @foreach(['pi_currency' => __('Proforma Invoices in'), 'supplier_currency' => __('Supplier orders in'), 'book_currency' => __('Books kept in')] as $field => $label)
                <div class="col-md-4">
                    <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                    <select id="{{ $field }}" name="{{ $field }}" class="form-select @error($field) is-invalid @enderror">
                        @foreach($currencies as $code => $name)
                            <option value="{{ $code }}" @selected(old($field, $setting->{$field}) === $code)>{{ $code }} — {{ $name }}</option>
                        @endforeach
                    </select>
                    @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endforeach
        </div>

        <h2 class="h6 mb-3">{{ __('Approvals and who sees what') }}</h2>
        <div class="row g-2 mb-4">
            @foreach([
                'pi_issue_requires_approval' => __('Issuing a Proforma Invoice needs a manager\'s approval'),
                'payments_out_require_approval' => __('Every payment out needs a manager\'s approval'),
                'sales_see_supplier_prices' => __('Sales staff see suppliers and supplier prices'),
                'sales_own_clients_only' => __('Sales staff see only the clients they manage'),
                'block_loading_unpaid' => __('Goods are not loaded until the client has paid in full (a manager can override)'),
            ] as $flag => $label)
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="{{ $flag }}" name="{{ $flag }}" value="1" @checked(old($flag, $setting->{$flag}))>
                        <label class="form-check-label" for="{{ $flag }}">{{ $label }}</label>
                    </div>
                </div>
            @endforeach
        </div>

        <h2 class="h6 mb-3">{{ __('Warnings') }}</h2>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label" for="step_warning_days">{{ __('Warn this many days before a step is due') }}</label>
                <input id="step_warning_days" name="step_warning_days" type="number" min="0" max="90" class="form-control" value="{{ old('step_warning_days', $setting->step_warning_days) }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="eta_warning_days">{{ __('Warn this many days before a container arrives') }}</label>
                <input id="eta_warning_days" name="eta_warning_days" type="number" min="0" max="90" class="form-control" value="{{ old('eta_warning_days', $setting->eta_warning_days) }}">
            </div>
        </div>

        <h2 class="h6 mb-3">{{ __('Number prefixes') }}</h2>
        <div class="row g-3 mb-4">
            @foreach([
                'client_prefix' => __('Clients'), 'supplier_prefix' => __('Suppliers'), 'shipping_company_prefix' => __('Shipping companies'),
                'product_prefix' => __('Products'), 'pi_prefix' => __('Proforma invoices'), 'supplier_order_prefix' => __('Supplier orders'),
                'shipment_prefix' => __('Shipments'), 'payment_prefix' => __('Payments'), 'journal_prefix' => __('Journal entries'),
            ] as $field => $label)
                <div class="col-6 col-md-2">
                    <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" class="form-control @error($field) is-invalid @enderror" dir="ltr" value="{{ old($field, $setting->{$field}) }}">
                    @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endforeach
        </div>
        </fieldset>
        @if($editable)<button class="btn btn-primary">{{ __('Save options') }}</button>@endif
    </form>
@endif

@if($tab === 'lists')
    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach($lists as $key => $label)
            <a href="{{ route('configuration.index', ['tab' => 'lists', 'list' => $key]) }}" class="btn btn-sm {{ $list === $key ? 'btn-primary' : 'btn-light' }}">{{ __($label) }}</a>
        @endforeach
    </div>
    @if($list === 'container_status')
        <div class="alert alert-info">{{ __('A container moves through these statuses in this order. Going back needs a correction, so keep the order the way things really happen.') }}</div>
    @endif
    <div class="app-card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Code') }}</th><th>{{ __('Name (English)') }}</th><th>{{ __('Name (Arabic)') }}</th><th>{{ __('Name (Chinese)') }}</th>
                    @foreach($meta as $key => $label)<th>{{ __($label) }}</th>@endforeach
                    <th>{{ __('Order') }}</th><th>{{ __('Active') }}</th><th></th>
                </tr></thead>
                <tbody>
                @foreach($entries as $entry)
                    @php $f = 'lookup-'.$entry->id; @endphp
                    <tr>
                            <td><span class="app-ltr fw-semibold">{{ $entry->code }}</span></td>
                            <td><input form="{{ $f }}" name="name" class="form-control form-control-sm" value="{{ $entry->name }}" required @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="name_ar" class="form-control form-control-sm" dir="rtl" value="{{ $entry->name_ar }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="name_zh" class="form-control form-control-sm" value="{{ $entry->name_zh }}" @disabled(! $editable)></td>
                            @foreach($meta as $key => $label)
                                <td>
                                    @if($key === 'side')
                                        <select form="{{ $f }}" name="meta_side" class="form-select form-select-sm" @disabled(! $editable)>
                                            <option value=""></option>
                                            <option value="loading" @selected(($entry->meta['side'] ?? null) === 'loading')>{{ __('Loading') }}</option>
                                            <option value="discharge" @selected(($entry->meta['side'] ?? null) === 'discharge')>{{ __('Discharge') }}</option>
                                        </select>
                                    @else
                                        <input form="{{ $f }}" name="meta_{{ $key }}" type="number" step="0.01" min="0" class="form-control form-control-sm" style="max-inline-size:110px" value="{{ $entry->meta[$key] ?? '' }}" @disabled(! $editable)>
                                    @endif
                                </td>
                            @endforeach
                            <td><input form="{{ $f }}" name="sort" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ $entry->sort }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" class="form-check-input" type="checkbox" name="active" value="1" @checked($entry->active) @disabled(! $editable) aria-label="{{ __('Active') }}"></td>
                            <td>@if($editable)<form id="{{ $f }}" method="POST" action="{{ route('configuration.lookups.update', $entry) }}">@csrf @method('PUT')<button class="btn btn-sm btn-light" title="{{ __('Save') }}"><i class="bi bi-check2"></i></button></form>@endif</td>
                    </tr>
                @endforeach
                @if($editable)
                    @php $f = 'lookup-new'; @endphp
                    <tr class="table-light">
                            <td><input form="{{ $f }}" name="code" class="form-control form-control-sm @error('code') is-invalid @enderror" dir="ltr" placeholder="{{ __('New code') }}" value="{{ old('code') }}" required></td>
                            <td><input form="{{ $f }}" name="name" class="form-control form-control-sm" value="{{ old('name') }}" required></td>
                            <td><input form="{{ $f }}" name="name_ar" class="form-control form-control-sm" dir="rtl" value="{{ old('name_ar') }}"></td>
                            <td><input form="{{ $f }}" name="name_zh" class="form-control form-control-sm" value="{{ old('name_zh') }}"></td>
                            @foreach($meta as $key => $label)
                                <td>
                                    @if($key === 'side')
                                        <select form="{{ $f }}" name="meta_side" class="form-select form-select-sm"><option value=""></option><option value="loading">{{ __('Loading') }}</option><option value="discharge">{{ __('Discharge') }}</option></select>
                                    @else
                                        <input form="{{ $f }}" name="meta_{{ $key }}" type="number" step="0.01" min="0" class="form-control form-control-sm" style="max-inline-size:110px">
                                    @endif
                                </td>
                            @endforeach
                            <td><input form="{{ $f }}" name="sort" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ old('sort', ($entries->max('sort') ?? 0) + 10) }}" required></td>
                            <td></td>
                            <td><form id="{{ $f }}" method="POST" action="{{ route('configuration.lookups.store') }}">@csrf<input type="hidden" name="list" value="{{ $list }}"><button class="btn btn-sm btn-primary" title="{{ __('Add') }}"><i class="bi bi-plus-lg"></i></button></form></td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
    @error('code')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@endif

@if($tab === 'steps')
    <div class="alert alert-info">{{ __('These are the columns of the order follow-up board, from MASDAR\'s follow-up sheet. "Due" counts days before the promised delivery date (a negative number means after it); leave it empty for no due date.') }}</div>
    <div class="app-card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Code') }}</th><th>{{ __('Name (English)') }}</th><th>{{ __('Name (Arabic)') }}</th><th>{{ __('Name (Chinese)') }}</th>
                    <th>{{ __('Completed by') }}</th><th>{{ __('Needs a file') }}</th><th>{{ __('Due (days before delivery)') }}</th><th>{{ __('Order') }}</th><th>{{ __('Active') }}</th><th></th>
                </tr></thead>
                <tbody>
                @php $kinds = ['manual' => __('Marked by hand'), 'payment' => __('A payment'), 'receipt' => __('Goods receipt'), 'booking' => __('Booking a shipment'), 'loading' => __('Loading the container')]; @endphp
                @foreach($steps as $step)
                    @php $f = 'step-'.$step->id; @endphp
                    <tr>
                            <td><span class="app-ltr small">{{ $step->code }}</span></td>
                            <td><input form="{{ $f }}" name="name" class="form-control form-control-sm" value="{{ $step->name }}" required @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="name_ar" class="form-control form-control-sm" dir="rtl" value="{{ $step->name_ar }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="name_zh" class="form-control form-control-sm" value="{{ $step->name_zh }}" @disabled(! $editable)></td>
                            <td>
                                <select form="{{ $f }}" name="kind" class="form-select form-select-sm" @disabled(! $editable)>
                                    @foreach($kinds as $value => $label)<option value="{{ $value }}" @selected($step->kind === $value)>{{ $label }}</option>@endforeach
                                </select>
                            </td>
                            <td><input form="{{ $f }}" class="form-check-input" type="checkbox" name="requires_attachment" value="1" @checked($step->requires_attachment) @disabled(! $editable) aria-label="{{ __('Needs a file') }}"></td>
                            <td><input form="{{ $f }}" name="due_offset_days" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ $step->due_offset_days }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="sort" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ $step->sort }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" class="form-check-input" type="checkbox" name="active" value="1" @checked($step->active) @disabled(! $editable) aria-label="{{ __('Active') }}"></td>
                            <td>@if($editable)<form id="{{ $f }}" method="POST" action="{{ route('configuration.steps.update', $step) }}">@csrf @method('PUT')<button class="btn btn-sm btn-light" title="{{ __('Save') }}"><i class="bi bi-check2"></i></button></form>@endif</td>
                    </tr>
                @endforeach
                @if($editable)
                    @php $f = 'step-new'; @endphp
                    <tr class="table-light">
                            <td><input form="{{ $f }}" name="code" class="form-control form-control-sm @error('code') is-invalid @enderror" dir="ltr" placeholder="{{ __('new_step') }}" required></td>
                            <td><input form="{{ $f }}" name="name" class="form-control form-control-sm" required></td>
                            <td><input form="{{ $f }}" name="name_ar" class="form-control form-control-sm" dir="rtl"></td>
                            <td><input form="{{ $f }}" name="name_zh" class="form-control form-control-sm"></td>
                            <td><select form="{{ $f }}" name="kind" class="form-select form-select-sm">@foreach($kinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></td>
                            <td><input form="{{ $f }}" class="form-check-input" type="checkbox" name="requires_attachment" value="1" aria-label="{{ __('Needs a file') }}"></td>
                            <td><input form="{{ $f }}" name="due_offset_days" type="number" class="form-control form-control-sm" style="max-inline-size:90px"></td>
                            <td><input form="{{ $f }}" name="sort" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ ($steps->max('sort') ?? 0) + 10 }}" required></td>
                            <td></td>
                            <td><form id="{{ $f }}" method="POST" action="{{ route('configuration.steps.store') }}">@csrf<button class="btn btn-sm btn-primary" title="{{ __('Add') }}"><i class="bi bi-plus-lg"></i></button></form></td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
    @error('code')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
@endif
@endsection
