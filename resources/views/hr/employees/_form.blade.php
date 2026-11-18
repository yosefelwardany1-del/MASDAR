{{-- Shared by create and edit; $employee is null on create. The code is given when it is saved;
     salaries, documents and leave are added on the employee's page. --}}
@php $v = fn (string $field) => old($field, $employee?->{$field} instanceof \DateTimeInterface ? $employee->{$field}->format('Y-m-d') : $employee?->{$field}); @endphp

<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-person-vcard" aria-hidden="true"></i>{{ __('Who they are') }}</legend>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="f-name">{{ __('Name (English)') }}<span class="app-req" aria-hidden="true">*</span></label>
            <input type="text" name="name" id="f-name" class="form-control @error('name') is-invalid @enderror" value="{{ $v('name') }}" maxlength="200" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="f-name_local">{{ __('Name in Chinese or Arabic') }}</label>
            <input type="text" name="name_local" id="f-name_local" dir="auto" class="form-control @error('name_local') is-invalid @enderror" value="{{ $v('name_local') }}" maxlength="200">
            @error('name_local')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">@include('hr._select', ['name' => 'nationality', 'label' => __('Nationality'), 'options' => $countries, 'value' => $employee?->nationality])</div>
        <div class="col-md-4">
            <label class="form-label" for="f-birth_date">{{ __('Date of birth') }}</label>
            <input type="date" name="birth_date" id="f-birth_date" class="form-control @error('birth_date') is-invalid @enderror" value="{{ $v('birth_date') }}">
            @error('birth_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="f-id_number">{{ __('ID or passport number') }}</label>
            <input type="text" name="id_number" id="f-id_number" dir="ltr" class="form-control @error('id_number') is-invalid @enderror" value="{{ $v('id_number') }}" maxlength="80" aria-describedby="f-private-help">
            @error('id_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12"><div class="form-text mt-0" id="f-private-help"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('ID numbers, date of birth, address and bank details are shown only to HR and payroll.') }}</div></div>
    </div>
</fieldset>

<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-briefcase" aria-hidden="true"></i>{{ __('The job') }}</legend>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="f-job_title">{{ __('Job title') }}</label>
            <input type="text" name="job_title" id="f-job_title" class="form-control @error('job_title') is-invalid @enderror" value="{{ $v('job_title') }}" maxlength="120">
            @error('job_title')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">@include('hr._select', ['name' => 'department', 'label' => __('Department'), 'options' => $departments, 'value' => $employee?->department])</div>
        <div class="col-md-4">@include('hr._select', ['name' => 'work_location', 'label' => __('Work location'), 'options' => $locations, 'value' => $employee?->work_location])</div>
        <div class="col-md-4">@include('hr._select', ['name' => 'employment_type', 'label' => __('Employment type'), 'options' => $types, 'value' => $employee?->employment_type])</div>
        <div class="col-md-4">@include('hr._select', ['name' => 'salary_currency', 'label' => __('Paid in'), 'options' => $currencies, 'value' => $employee?->salary_currency ?? 'CNY', 'required' => true])</div>
        <div class="col-md-4">
            <label class="form-label" for="f-joined_on">{{ __('Joined on') }}<span class="app-req" aria-hidden="true">*</span></label>
            <input type="date" name="joined_on" id="f-joined_on" class="form-control @error('joined_on') is-invalid @enderror" value="{{ $v('joined_on') }}" required>
            @error('joined_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="f-left_on">{{ __('Left on') }}</label>
            <input type="date" name="left_on" id="f-left_on" class="form-control @error('left_on') is-invalid @enderror" value="{{ $v('left_on') }}" aria-describedby="f-left-help">
            @error('left_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text" id="f-left-help">{{ __('Empty while they work here. Their history stays.') }}</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="f-user_id">{{ __('Their user account') }}</label>
            <select name="user_id" id="f-user_id" class="form-select @error('user_id') is-invalid @enderror" aria-describedby="f-user-help">
                <option value="">{{ __('None') }}</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}" @selected((int) $v('user_id') === $u->id)>{{ $u->name }} · {{ $u->email }}</option>
                @endforeach
            </select>
            @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text" id="f-user-help">{{ __('Linked, they request their own leave under "My leave".') }}</div>
        </div>
    </div>
</fieldset>

<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-telephone" aria-hidden="true"></i>{{ __('How to reach them') }}</legend>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="f-phone">{{ __('Phone') }}</label>
            <input type="tel" name="phone" id="f-phone" dir="ltr" class="form-control @error('phone') is-invalid @enderror" value="{{ $v('phone') }}" maxlength="60">
            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="f-email">{{ __('E-mail') }}</label>
            <input type="email" name="email" id="f-email" dir="ltr" class="form-control @error('email') is-invalid @enderror" value="{{ $v('email') }}" maxlength="160">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="f-emergency_contact">{{ __('Emergency contact') }}</label>
            <input type="text" name="emergency_contact" id="f-emergency_contact" dir="auto" class="form-control @error('emergency_contact') is-invalid @enderror" value="{{ $v('emergency_contact') }}" placeholder="{{ __('Name, relation, phone') }}">
            @error('emergency_contact')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="f-address">{{ __('Address') }}</label>
            <textarea name="address" id="f-address" rows="2" dir="auto" class="form-control @error('address') is-invalid @enderror">{{ $v('address') }}</textarea>
            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="f-bank_details"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('Salary bank account') }}</label>
            <textarea name="bank_details" id="f-bank_details" rows="2" dir="auto" class="form-control @error('bank_details') is-invalid @enderror" placeholder="{{ __('Bank, account name and number') }}">{{ $v('bank_details') }}</textarea>
            @error('bank_details')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <label class="form-label" for="f-notes">{{ __('Notes') }}</label>
            <textarea name="notes" id="f-notes" rows="2" dir="auto" class="form-control @error('notes') is-invalid @enderror">{{ $v('notes') }}</textarea>
            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</fieldset>
