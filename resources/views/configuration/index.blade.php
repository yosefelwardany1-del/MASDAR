@extends('layouts.app')

@section('title', __('Lists & options'))

@section('content')
<x-page-header :title="__('Lists & options')" :subtitle="__('Every choice MASDAR has not settled yet lives here. Change it at any time: documents keep the codes they were made with.')">
    @unless($editable)
        <x-slot:meta><x-status tone="neutral" icon="eye">{{ __('Read only') }}</x-status></x-slot:meta>
    @endunless
</x-page-header>

<nav aria-label="{{ __('Lists & options') }}">
    <ul class="nav nav-tabs app-subnav">
        @foreach(['options' => [__('Business options'), 'sliders'], 'lists' => [__('Lists'), 'list-ul'], 'steps' => [__('Order follow-up steps'), 'kanban']] as $key => [$label, $icon])
            <li class="nav-item"><a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('configuration.index', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</a></li>
        @endforeach
    </ul>
</nav>

@if($tab === 'options')
    <form method="POST" action="{{ route('configuration.options.update') }}" class="app-form">
        @csrf @method('PUT')
        @include('configuration._form-errors')
        <fieldset @disabled(! $editable) class="app-form">
            <x-section :title="__('Proforma Invoice letterhead and terms')" icon="file-earmark-text">
                <div class="row g-3">
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
                        <div class="input-group has-validation">
                            <input id="default_deposit_percent" name="default_deposit_percent" type="number" step="0.01" min="0" max="100" class="form-control app-num @error('default_deposit_percent') is-invalid @enderror" value="{{ old('default_deposit_percent', $setting->default_deposit_percent) }}">
                            <span class="input-group-text">%</span>
                            @error('default_deposit_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="pi_terms_template">{{ __('Payment terms on the PI') }}</label>
                        <input id="pi_terms_template" name="pi_terms_template" class="form-control" dir="ltr" value="{{ old('pi_terms_template', $setting->pi_terms_template) }}" aria-describedby="pi-terms-help">
                        <div class="form-text" id="pi-terms-help">{{ __('{deposit} and {final} are replaced by the PI\'s percentages.') }} <span class="app-ltr">{{ $setting->piTerms($setting->default_deposit_percent) }}</span></div>
                    </div>
                </div>
            </x-section>

            <x-section :title="__('Currencies')" icon="currency-exchange">
                <div class="row g-3">
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
            </x-section>

            <x-section :title="__('Approvals and who sees what')" icon="shield-check">
                @foreach([
                    'pi_issue_requires_approval' => __('Issuing a Proforma Invoice needs a manager\'s approval'),
                    'payments_out_require_approval' => __('Every payment out needs a manager\'s approval'),
                    'sales_see_supplier_prices' => __('Sales staff see suppliers and supplier prices'),
                    'sales_own_clients_only' => __('Sales staff see only the clients they manage'),
                    'block_loading_unpaid' => __('Goods are not loaded until the client has paid in full (a manager can override)'),
                ] as $flag => $label)
                    <div class="app-switch">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="{{ $flag }}" name="{{ $flag }}" value="1" @checked(old($flag, $setting->{$flag}))>
                            <label class="form-check-label" for="{{ $flag }}">{{ $label }}</label>
                        </div>
                    </div>
                @endforeach
            </x-section>

            <x-section :title="__('Warnings')" icon="bell">
                <div class="row g-3">
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label" for="step_warning_days">{{ __('Warn this many days before a step is due') }}</label>
                        <input id="step_warning_days" name="step_warning_days" type="number" min="0" max="90" class="form-control app-num" value="{{ old('step_warning_days', $setting->step_warning_days) }}">
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label" for="eta_warning_days">{{ __('Warn this many days before a container arrives') }}</label>
                        <input id="eta_warning_days" name="eta_warning_days" type="number" min="0" max="90" class="form-control app-num" value="{{ old('eta_warning_days', $setting->eta_warning_days) }}">
                    </div>
                </div>
            </x-section>

            <x-section :title="__('WhatsApp updates to clients')" icon="whatsapp">
                <p class="form-text mt-0 mb-3">{{ __('When a container reaches one of these statuses, each client with goods inside gets MASDAR\'s approved WhatsApp message. Nothing is sent until the WhatsApp keys are set and the template is approved (see the README).') }}</p>
                <div class="app-switch mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="whatsapp_container_updates" name="whatsapp_container_updates" value="1" @checked(old('whatsapp_container_updates', $setting->whatsapp_container_updates))>
                        <label class="form-check-label" for="whatsapp_container_updates">{{ __('Send clients WhatsApp updates on their containers') }}</label>
                    </div>
                </div>
                <fieldset>
                    <legend class="form-label">{{ __('Send an update when a container is') }}</legend>
                    @php $chosen = old('whatsapp_update_statuses', $setting->whatsappUpdateStatuses()); @endphp
                    {{-- The row must follow the legend directly: Bootstrap floats a legend and clears only its next sibling. --}}
                    <div class="d-flex flex-wrap gap-3">
                        @foreach($containerStatuses as $code => $name)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="wa-status-{{ $code }}" name="whatsapp_update_statuses[]" value="{{ $code }}" @checked(in_array($code, (array) $chosen, true))>
                                <label class="form-check-label" for="wa-status-{{ $code }}">{{ $name }}</label>
                            </div>
                        @endforeach
                    </div>
                    <input type="hidden" name="whatsapp_update_statuses[]" value="">
                </fieldset>
            </x-section>

            <x-section :title="__('Quotations, contracts and invoices')" icon="file-earmark-text" id="documents">
                <div class="row g-3">
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="quotation_valid_days">{{ __('A quotation is valid for (days)') }}</label>
                        <input id="quotation_valid_days" name="quotation_valid_days" type="number" min="1" max="365" class="form-control app-num @error('quotation_valid_days') is-invalid @enderror" value="{{ old('quotation_valid_days', $setting->quotation_valid_days) }}">
                        @error('quotation_valid_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="default_incoterm">{{ __('Default incoterm') }}</label>
                        <input id="default_incoterm" name="default_incoterm" dir="ltr" class="form-control @error('default_incoterm') is-invalid @enderror" value="{{ old('default_incoterm', $setting->default_incoterm) }}" placeholder="FOB">
                        @error('default_incoterm')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" for="invoice_bank_details">{{ __('Bank details on the commercial invoice') }}</label>
                        <textarea id="invoice_bank_details" name="invoice_bank_details" rows="3" dir="ltr" class="form-control @error('invoice_bank_details') is-invalid @enderror" placeholder="{{ __('Bank, account name, account number, SWIFT') }}">{{ old('invoice_bank_details', $setting->invoice_bank_details) }}</textarea>
                        <div class="form-text">{{ __('Empty: the invoice shows none.') }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="purchase_contract_terms">{{ __('Terms of the purchase contract (English and Chinese)') }}</label>
                        <textarea id="purchase_contract_terms" name="purchase_contract_terms" rows="6" class="form-control @error('purchase_contract_terms') is-invalid @enderror" placeholder="{{ \App\Models\BusinessSetting::DEFAULT_PURCHASE_CONTRACT_TERMS }}">{{ old('purchase_contract_terms', $setting->purchase_contract_terms) }}</textarea>
                        <div class="form-text">{{ __('Empty: the standard terms shown here are printed. Write MASDAR\'s own to replace them.') }}</div>
                    </div>
                </div>
            </x-section>

            <x-section :title="__('Profit and ageing')" icon="graph-up-arrow" id="profit">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="container_cost_split">{{ __('A shared container\'s costs are split') }}</label>
                        <select id="container_cost_split" name="container_cost_split" class="form-select @error('container_cost_split') is-invalid @enderror">
                            @foreach(['cbm' => __('by CBM loaded'), 'kg' => __('by weight loaded'), 'value' => __('by the value of the goods loaded'), 'cartons' => __('by cartons loaded')] as $value => $label)
                                <option value="{{ $value }}" @selected(old('container_cost_split', $setting->container_cost_split) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{{ __('Between the clients and orders inside it, on the Profit screen (spec Q-C3).') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ageing_buckets">{{ __('Ageing buckets (days)') }}</label>
                        <input id="ageing_buckets" name="ageing_buckets" dir="ltr" class="form-control app-num @error('ageing_buckets') is-invalid @enderror" value="{{ old('ageing_buckets', $setting->ageing_buckets) }}" placeholder="30,60,90">
                        @error('ageing_buckets')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('The limits of each column of the ageing report, separated by commas.') }}</div>
                    </div>
                </div>
            </x-section>

            <x-section :title="__('HR and payroll')" icon="people" id="hr-payroll">
                <p class="form-text mt-0 mb-3">{{ __('Rates and bases are left empty until MASDAR\'s accountant gives them: payroll then deducts nothing and says so. Leave types and their days a year are under Lists.') }}</p>
                <div class="row g-3">
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="payroll_currency">{{ __('Payroll currency') }}</label>
                        <select id="payroll_currency" name="payroll_currency" class="form-select">
                            @foreach($currencies as $code => $name)<option value="{{ $code }}" @selected(old('payroll_currency', $setting->payroll_currency) === $code)>{{ $code }} · {{ $name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="payroll_days_per_month">{{ __('Working days a month (daily rate)') }}</label>
                        <input id="payroll_days_per_month" name="payroll_days_per_month" inputmode="decimal" dir="ltr" class="form-control app-num @error('payroll_days_per_month') is-invalid @enderror" value="{{ old('payroll_days_per_month', $setting->payroll_days_per_month) }}">
                        @error('payroll_days_per_month')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="payroll_overtime_multiplier">{{ __('Overtime pay multiplier') }}</label>
                        <input id="payroll_overtime_multiplier" name="payroll_overtime_multiplier" inputmode="decimal" dir="ltr" class="form-control app-num @error('payroll_overtime_multiplier') is-invalid @enderror" value="{{ old('payroll_overtime_multiplier', $setting->payroll_overtime_multiplier) }}">
                        @error('payroll_overtime_multiplier')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label" for="hr_document_warning_days">{{ __('Warn this many days before a document expires') }}</label>
                        <input id="hr_document_warning_days" name="hr_document_warning_days" type="number" min="0" max="365" class="form-control app-num @error('hr_document_warning_days') is-invalid @enderror" value="{{ old('hr_document_warning_days', $setting->hr_document_warning_days) }}">
                        @error('hr_document_warning_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <fieldset>
                            <legend class="form-label">{{ __('Working days of the week') }}</legend>
                            <div class="d-flex flex-wrap gap-3">
                                @foreach(range(1, 7) as $dow)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="wd-{{ $dow }}" name="hr_work_days[]" value="{{ $dow }}" @checked(in_array($dow, $setting->workDays(), true))>
                                        <label class="form-check-label" for="wd-{{ $dow }}">{{ \Carbon\Carbon::now()->locale(app()->getLocale())->startOfWeek(\Carbon\Carbon::MONDAY)->addDays($dow - 1)->isoFormat('dddd') }}</label>
                                    </div>
                                @endforeach
                            </div>
                            <input type="hidden" name="hr_work_days[]" value="">
                            @error('hr_work_days')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </fieldset>
                    </div>
                    @foreach([
                        'si_employee_percent' => __('Social insurance, employee %'), 'si_employer_percent' => __('Social insurance, employer %'),
                        'hf_employee_percent' => __('Housing fund, employee %'), 'hf_employer_percent' => __('Housing fund, employer %'),
                        'si_base_min' => __('Lowest contribution base'), 'si_base_max' => __('Highest contribution base'),
                    ] as $field => $label)
                        <div class="col-md-6 col-lg-4 col-xl-2">
                            <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                            <input id="{{ $field }}" name="{{ $field }}" inputmode="decimal" dir="ltr" class="form-control app-num @error($field) is-invalid @enderror" value="{{ old($field, $setting->{$field} === null ? '' : rtrim(rtrim((string) $setting->{$field}, '0'), '.')) }}" placeholder="{{ __('Not set') }}">
                            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
            </x-section>

            <x-section :title="__('Number prefixes')" icon="hash">
                <div class="row g-3">
                    @foreach([
                        'client_prefix' => __('Clients'), 'supplier_prefix' => __('Suppliers'), 'shipping_company_prefix' => __('Shipping companies'),
                        'product_prefix' => __('Products'), 'pi_prefix' => __('Proforma invoices'), 'supplier_order_prefix' => __('Supplier orders'),
                        'shipment_prefix' => __('Shipments'), 'payment_prefix' => __('Payments'), 'journal_prefix' => __('Journal entries'),
                        'employee_prefix' => __('Employees'), 'payroll_prefix' => __('Payroll runs'), 'quotation_prefix' => __('Quotations'),
                        'claim_prefix' => __('Claims'), 'credit_note_prefix' => __('Credit notes'), 'debit_note_prefix' => __('Debit notes'), 'sourcing_prefix' => __('Factory quotes'), 'expense_claim_prefix' => __('Expense claims'), 'asset_prefix' => __('Fixed assets'),
                    ] as $field => $label)
                        <div class="col-6 col-md-4 col-xl-2">
                            <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                            <input id="{{ $field }}" name="{{ $field }}" class="form-control app-num @error($field) is-invalid @enderror" dir="ltr" value="{{ old($field, $setting->{$field}) }}">
                            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
            </x-section>
        </fieldset>
        @if($editable)
            <div class="app-form__actions">
                <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save options') }}</button>
            </div>
        @endif
    </form>
@endif

@if($tab === 'lists')
    <nav class="app-chips" aria-label="{{ __('Lists') }}">
        @foreach($lists as $key => $label)
            <a href="{{ route('configuration.index', ['tab' => 'lists', 'list' => $key]) }}" class="app-chip {{ $list === $key ? 'is-active' : '' }}" @if($list === $key) aria-current="page" @endif>{{ __($label) }}</a>
        @endforeach
    </nav>
    @if($list === 'container_status')
        <div class="alert alert-info d-flex gap-2"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('A container moves through these statuses in this order. Going back needs a correction, so keep the order the way things really happen.') }}</span></div>
    @endif
    @error('code')<div class="alert alert-danger" role="alert"><span class="app-field-error text-reset">{{ $message }}</span></div>@enderror
    <x-section :title="__($lists[$list] ?? $list)" icon="list-ul" flush :subtitle="$editable ? __('Each row saves on its own; the last row adds a new entry.') : null">
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
                            <td><input form="{{ $f }}" name="name" aria-label="{{ __('Name (English)') }}" class="form-control form-control-sm" value="{{ $entry->name }}" required @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="name_ar" aria-label="{{ __('Name (Arabic)') }}" class="form-control form-control-sm" dir="rtl" value="{{ $entry->name_ar }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="name_zh" aria-label="{{ __('Name (Chinese)') }}" class="form-control form-control-sm" value="{{ $entry->name_zh }}" @disabled(! $editable)></td>
                            @foreach($meta as $key => $label)
                                <td>
                                    @if($key === 'side')
                                        <select form="{{ $f }}" name="meta_side" aria-label="{{ __($label) }}" class="form-select form-select-sm" @disabled(! $editable)>
                                            <option value=""></option>
                                            <option value="loading" @selected(($entry->meta['side'] ?? null) === 'loading')>{{ __('Loading') }}</option>
                                            <option value="discharge" @selected(($entry->meta['side'] ?? null) === 'discharge')>{{ __('Discharge') }}</option>
                                        </select>
                                    @elseif(array_key_exists($key, \App\Http\Controllers\Configuration\ConfigurationController::YES_NO_META))
                                        @php $yes = (int) ($entry->meta[$key] ?? \App\Http\Controllers\Configuration\ConfigurationController::YES_NO_META[$key]); @endphp
                                        <select form="{{ $f }}" name="meta_{{ $key }}" aria-label="{{ __($label) }}" class="form-select form-select-sm" @disabled(! $editable)>
                                            <option value="1" @selected($yes === 1)>{{ __('Yes') }}</option>
                                            <option value="0" @selected($yes === 0)>{{ __('No') }}</option>
                                        </select>
                                    @else
                                        <input form="{{ $f }}" name="meta_{{ $key }}" aria-label="{{ __($label) }}" type="number" step="0.01" min="0" class="form-control form-control-sm" style="max-inline-size:110px" value="{{ $entry->meta[$key] ?? '' }}" @disabled(! $editable)>
                                    @endif
                                </td>
                            @endforeach
                            <td><input form="{{ $f }}" name="sort" aria-label="{{ __('Order') }}" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ $entry->sort }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" class="form-check-input" type="checkbox" name="active" value="1" @checked($entry->active) @disabled(! $editable) aria-label="{{ __('Active') }}"></td>
                            <td>@if($editable)<form id="{{ $f }}" method="POST" action="{{ route('configuration.lookups.update', $entry) }}">@csrf @method('PUT')<button class="btn btn-sm btn-light" title="{{ __('Save') }}" aria-label="{{ __('Save') }}"><i class="bi bi-check2" aria-hidden="true"></i></button></form>@endif</td>
                    </tr>
                @endforeach
                @if($editable)
                    @php $f = 'lookup-new'; @endphp
                    <tr class="app-newrow">
                            <td><input form="{{ $f }}" name="code" aria-label="{{ __('Code') }}" class="form-control form-control-sm @error('code') is-invalid @enderror" dir="ltr" placeholder="{{ __('New code') }}" value="{{ old('code') }}" required></td>
                            <td><input form="{{ $f }}" name="name" aria-label="{{ __('Name (English)') }}" class="form-control form-control-sm" value="{{ old('name') }}" required></td>
                            <td><input form="{{ $f }}" name="name_ar" aria-label="{{ __('Name (Arabic)') }}" class="form-control form-control-sm" dir="rtl" value="{{ old('name_ar') }}"></td>
                            <td><input form="{{ $f }}" name="name_zh" aria-label="{{ __('Name (Chinese)') }}" class="form-control form-control-sm" value="{{ old('name_zh') }}"></td>
                            @foreach($meta as $key => $label)
                                <td>
                                    @if($key === 'side')
                                        <select form="{{ $f }}" name="meta_side" aria-label="{{ __($label) }}" class="form-select form-select-sm"><option value=""></option><option value="loading">{{ __('Loading') }}</option><option value="discharge">{{ __('Discharge') }}</option></select>
                                    @elseif(array_key_exists($key, \App\Http\Controllers\Configuration\ConfigurationController::YES_NO_META))
                                        @php $yes = \App\Http\Controllers\Configuration\ConfigurationController::YES_NO_META[$key]; @endphp
                                        <select form="{{ $f }}" name="meta_{{ $key }}" aria-label="{{ __($label) }}" class="form-select form-select-sm"><option value="1" @selected($yes === 1)>{{ __('Yes') }}</option><option value="0" @selected($yes === 0)>{{ __('No') }}</option></select>
                                    @else
                                        <input form="{{ $f }}" name="meta_{{ $key }}" aria-label="{{ __($label) }}" type="number" step="0.01" min="0" class="form-control form-control-sm" style="max-inline-size:110px">
                                    @endif
                                </td>
                            @endforeach
                            <td><input form="{{ $f }}" name="sort" aria-label="{{ __('Order') }}" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ old('sort', ($entries->max('sort') ?? 0) + 10) }}" required></td>
                            <td></td>
                            <td><form id="{{ $f }}" method="POST" action="{{ route('configuration.lookups.store') }}">@csrf<input type="hidden" name="list" value="{{ $list }}"><button class="btn btn-sm btn-primary" title="{{ __('Add') }}" aria-label="{{ __('Add') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i></button></form></td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </x-section>
@endif

@if($tab === 'steps')
    <div class="alert alert-info d-flex gap-2"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('These are the columns of the order follow-up board, from MASDAR\'s follow-up sheet. "Due" counts days before the promised delivery date (a negative number means after it); leave it empty for no due date.') }}</span></div>
    @error('code')<div class="alert alert-danger" role="alert"><span class="app-field-error text-reset">{{ $message }}</span></div>@enderror
    <x-section :title="__('Order follow-up steps')" icon="kanban" flush :subtitle="$editable ? __('Each row saves on its own; the last row adds a new entry.') : null">
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
                            <td><input form="{{ $f }}" name="name" aria-label="{{ __('Name (English)') }}" class="form-control form-control-sm" value="{{ $step->name }}" required @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="name_ar" aria-label="{{ __('Name (Arabic)') }}" class="form-control form-control-sm" dir="rtl" value="{{ $step->name_ar }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="name_zh" aria-label="{{ __('Name (Chinese)') }}" class="form-control form-control-sm" value="{{ $step->name_zh }}" @disabled(! $editable)></td>
                            <td>
                                <select form="{{ $f }}" name="kind" aria-label="{{ __('Completed by') }}" class="form-select form-select-sm" @disabled(! $editable)>
                                    @foreach($kinds as $value => $label)<option value="{{ $value }}" @selected($step->kind === $value)>{{ $label }}</option>@endforeach
                                </select>
                            </td>
                            <td><input form="{{ $f }}" class="form-check-input" type="checkbox" name="requires_attachment" value="1" @checked($step->requires_attachment) @disabled(! $editable) aria-label="{{ __('Needs a file') }}"></td>
                            <td><input form="{{ $f }}" name="due_offset_days" aria-label="{{ __('Due (days before delivery)') }}" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ $step->due_offset_days }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" name="sort" aria-label="{{ __('Order') }}" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ $step->sort }}" @disabled(! $editable)></td>
                            <td><input form="{{ $f }}" class="form-check-input" type="checkbox" name="active" value="1" @checked($step->active) @disabled(! $editable) aria-label="{{ __('Active') }}"></td>
                            <td>@if($editable)<form id="{{ $f }}" method="POST" action="{{ route('configuration.steps.update', $step) }}">@csrf @method('PUT')<button class="btn btn-sm btn-light" title="{{ __('Save') }}" aria-label="{{ __('Save') }}"><i class="bi bi-check2" aria-hidden="true"></i></button></form>@endif</td>
                    </tr>
                @endforeach
                @if($editable)
                    @php $f = 'step-new'; @endphp
                    <tr class="app-newrow">
                            <td><input form="{{ $f }}" name="code" aria-label="{{ __('Code') }}" class="form-control form-control-sm @error('code') is-invalid @enderror" dir="ltr" placeholder="{{ __('new_step') }}" required></td>
                            <td><input form="{{ $f }}" name="name" aria-label="{{ __('Name (English)') }}" class="form-control form-control-sm" required></td>
                            <td><input form="{{ $f }}" name="name_ar" aria-label="{{ __('Name (Arabic)') }}" class="form-control form-control-sm" dir="rtl"></td>
                            <td><input form="{{ $f }}" name="name_zh" aria-label="{{ __('Name (Chinese)') }}" class="form-control form-control-sm"></td>
                            <td><select form="{{ $f }}" name="kind" aria-label="{{ __('Completed by') }}" class="form-select form-select-sm">@foreach($kinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></td>
                            <td><input form="{{ $f }}" class="form-check-input" type="checkbox" name="requires_attachment" value="1" aria-label="{{ __('Needs a file') }}"></td>
                            <td><input form="{{ $f }}" name="due_offset_days" aria-label="{{ __('Due (days before delivery)') }}" type="number" class="form-control form-control-sm" style="max-inline-size:90px"></td>
                            <td><input form="{{ $f }}" name="sort" aria-label="{{ __('Order') }}" type="number" class="form-control form-control-sm" style="max-inline-size:90px" value="{{ ($steps->max('sort') ?? 0) + 10 }}" required></td>
                            <td></td>
                            <td><form id="{{ $f }}" method="POST" action="{{ route('configuration.steps.store') }}">@csrf<button class="btn btn-sm btn-primary" title="{{ __('Add') }}" aria-label="{{ __('Add') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i></button></form></td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </x-section>
@endif
@endsection
