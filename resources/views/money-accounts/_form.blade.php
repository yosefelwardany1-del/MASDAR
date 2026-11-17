{{-- Shared by create and edit. $account is null on create. The form pattern: titled sections,
     required fields marked, help under the field, the error right under it. --}}
@php use App\Models\MoneyAccount; @endphp
@include('configuration._form-errors')
<p class="app-form__legend"><span class="app-req" aria-hidden="true">*</span>{{ __('Required field') }}</p>

<x-section :title="__('The account')" icon="bank">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="ma-name">{{ __('Name') }}<span class="app-req" aria-hidden="true">*</span></label>
            <input type="text" name="name" id="ma-name" maxlength="120" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $account?->name) }}" required placeholder="{{ __('e.g. HSBC Hong Kong USD') }}">
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="ma-kind">{{ __('Kind') }}<span class="app-req" aria-hidden="true">*</span></label>
            <select name="kind" id="ma-kind" class="form-select @error('kind') is-invalid @enderror" required>
                @foreach(MoneyAccount::KINDS as $kind)
                    <option value="{{ $kind }}" @selected(old('kind', $account?->kind) === $kind)>{{ MoneyAccount::kindLabel($kind) }}</option>
                @endforeach
            </select>
            @error('kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="ma-currency">{{ __('Currency') }}<span class="app-req" aria-hidden="true">*</span></label>
            @if($currencyLocked)
                <input type="hidden" name="currency" value="{{ $account->currency }}">
                <input type="text" id="ma-currency" class="form-control" value="{{ $account->currency }}" disabled aria-describedby="ma-currency-help">
                <div class="form-text" id="ma-currency-help"><i class="bi bi-lock me-1" aria-hidden="true"></i>{{ __('This account has payments; its currency cannot change.') }}</div>
            @else
                <select name="currency" id="ma-currency" class="form-select @error('currency') is-invalid @enderror" required>
                    @foreach($currencies as $code => $name)
                        <option value="{{ $code }}" @selected(old('currency', $account?->currency ?? $book) === $code)>{{ $code }} — {{ $name }}</option>
                    @endforeach
                </select>
            @endif
            @error('currency')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="ma-location">{{ __('Where') }}<span class="app-req" aria-hidden="true">*</span></label>
            <select name="location" id="ma-location" class="form-select @error('location') is-invalid @enderror" required>
                @foreach(MoneyAccount::LOCATIONS as $location)
                    <option value="{{ $location }}" @selected(old('location', $account?->location) === $location)>{{ MoneyAccount::locationLabel($location) }}</option>
                @endforeach
            </select>
            @error('location')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</x-section>

<x-section :title="__('In the ledger')" icon="journal-text">
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label" for="ma-ledger">{{ __('Ledger account') }}</label>
            <select name="ledger_account_id" id="ma-ledger" class="form-select @error('ledger_account_id') is-invalid @enderror" aria-describedby="ma-ledger-help">
                <option value="">{{ __('Not set yet') }}</option>
                @foreach($ledgerAccounts as $ledger)
                    <option value="{{ $ledger->id }}" @selected((int) old('ledger_account_id', $account?->ledger_account_id) === $ledger->id)>{{ $ledger->code }} — {{ $ledger->displayName() }}{{ $ledger->currency ? ' ('.$ledger->currency.')' : '' }}</option>
                @endforeach
            </select>
            @error('ledger_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text" id="ma-ledger-help">{{ __('The chart-of-accounts line this account posts to. Payments are not posted until it is set.') }}</div>
        </div>
    </div>
</x-section>

<x-section :title="__('Status and details')" icon="sliders">
    <div class="row g-3">
        <div class="col-12">
            <input type="hidden" name="active" value="0">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="active" value="1" id="ma-active" @checked(old('active', $account?->active ?? true))>
                <label class="form-check-label" for="ma-active">{{ __('Active (new payments can use it)') }}</label>
            </div>
        </div>
        <div class="col-12">
            <label class="form-label" for="ma-details">{{ __('Details') }}</label>
            <textarea name="details" id="ma-details" rows="3" class="form-control @error('details') is-invalid @enderror" placeholder="{{ __('Bank, account number, holder, agent contact…') }}">{{ old('details', $account?->details) }}</textarea>
            @error('details')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</x-section>
