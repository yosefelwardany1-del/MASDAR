{{-- Shared by create and edit. $account is null on create. --}}
@php use App\Models\MoneyAccount; @endphp
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">{{ __('Name') }} <span class="text-danger">*</span></label>
        <input type="text" name="name" maxlength="120" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $account?->name) }}" required placeholder="{{ __('e.g. HSBC Hong Kong USD') }}">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Kind') }} <span class="text-danger">*</span></label>
        <select name="kind" class="form-select @error('kind') is-invalid @enderror" required>
            @foreach(MoneyAccount::KINDS as $kind)
                <option value="{{ $kind }}" @selected(old('kind', $account?->kind) === $kind)>{{ MoneyAccount::kindLabel($kind) }}</option>
            @endforeach
        </select>
        @error('kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Currency') }} <span class="text-danger">*</span></label>
        @if($currencyLocked)
            <input type="hidden" name="currency" value="{{ $account->currency }}">
            <input type="text" class="form-control" value="{{ $account->currency }}" disabled>
            <div class="form-text">{{ __('This account has payments; its currency cannot change.') }}</div>
        @else
            <select name="currency" class="form-select @error('currency') is-invalid @enderror" required>
                @foreach($currencies as $code => $name)
                    <option value="{{ $code }}" @selected(old('currency', $account?->currency ?? $book) === $code)>{{ $code }} — {{ $name }}</option>
                @endforeach
            </select>
        @endif
        @error('currency')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Where') }} <span class="text-danger">*</span></label>
        <select name="location" class="form-select @error('location') is-invalid @enderror" required>
            @foreach(MoneyAccount::LOCATIONS as $location)
                <option value="{{ $location }}" @selected(old('location', $account?->location) === $location)>{{ MoneyAccount::locationLabel($location) }}</option>
            @endforeach
        </select>
        @error('location')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Ledger account') }}</label>
        <select name="ledger_account_id" class="form-select @error('ledger_account_id') is-invalid @enderror">
            <option value="">{{ __('Not set yet') }}</option>
            @foreach($ledgerAccounts as $ledger)
                <option value="{{ $ledger->id }}" @selected((int) old('ledger_account_id', $account?->ledger_account_id) === $ledger->id)>{{ $ledger->code }} — {{ $ledger->displayName() }}{{ $ledger->currency ? ' ('.$ledger->currency.')' : '' }}</option>
            @endforeach
        </select>
        <div class="form-text">{{ __('The chart-of-accounts line this account posts to. Payments are not posted until it is set.') }}</div>
        @error('ledger_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 d-flex align-items-end">
        <div class="form-check mb-2">
            <input type="hidden" name="active" value="0">
            <input class="form-check-input" type="checkbox" name="active" value="1" id="ma-active" @checked(old('active', $account?->active ?? true))>
            <label class="form-check-label" for="ma-active">{{ __('Active (new payments can use it)') }}</label>
        </div>
    </div>
    <div class="col-12">
        <label class="form-label">{{ __('Details') }}</label>
        <textarea name="details" rows="3" class="form-control @error('details') is-invalid @enderror" placeholder="{{ __('Bank, account number, holder, agent contact…') }}">{{ old('details', $account?->details) }}</textarea>
        @error('details')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
