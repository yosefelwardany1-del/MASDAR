{{-- A leave request form. $action, $types (LeaveQueries::types), $employees (null: the caller's own). --}}
<form method="POST" action="{{ $action }}" class="app-form">
    @csrf
    <div class="row g-3">
        @if($employees !== null)
            <div class="col-md-6">
                <label class="form-label" for="lv-employee">{{ __('Employee') }}<span class="app-req" aria-hidden="true">*</span></label>
                <select name="employee_id" id="lv-employee" class="form-select @error('employee_id') is-invalid @enderror" required>
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach($employees as $e)<option value="{{ $e->id }}" @selected((int) old('employee_id') === $e->id)>{{ $e->name }} · {{ $e->code }}</option>@endforeach
                </select>
                @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-md-{{ $employees !== null ? 6 : 12 }}">
            <label class="form-label" for="lv-type">{{ __('Kind of leave') }}<span class="app-req" aria-hidden="true">*</span></label>
            <select name="leave_type" id="lv-type" class="form-select @error('leave_type') is-invalid @enderror" required>
                @foreach($types as $code => $t)<option value="{{ $code }}" @selected(old('leave_type') === $code)>{{ $t['label'] }}@unless($t['paid']) ({{ __('unpaid') }})@endunless</option>@endforeach
            </select>
            @error('leave_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="lv-from">{{ __('First day') }}<span class="app-req" aria-hidden="true">*</span></label>
            <input type="date" name="starts_on" id="lv-from" class="form-control @error('starts_on') is-invalid @enderror" value="{{ old('starts_on') }}" required>
            @error('starts_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="lv-to">{{ __('Last day') }}<span class="app-req" aria-hidden="true">*</span></label>
            <input type="date" name="ends_on" id="lv-to" class="form-control @error('ends_on') is-invalid @enderror" value="{{ old('ends_on') }}" required>
            @error('ends_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="lv-days">{{ __('Days') }}</label>
            <input name="days" id="lv-days" inputmode="decimal" dir="ltr" class="form-control app-num @error('days') is-invalid @enderror" value="{{ old('days') }}" aria-describedby="lv-days-help">
            @error('days')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text" id="lv-days-help">{{ __('Empty: the working days between the dates. 0.5 for half a day.') }}</div>
        </div>
        <div class="col-12">
            <label class="form-label" for="lv-reason">{{ __('Reason') }}</label>
            <input name="reason" id="lv-reason" dir="auto" class="form-control @error('reason') is-invalid @enderror" value="{{ old('reason') }}" maxlength="2000">
        </div>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-send" aria-hidden="true"></i>{{ $employees !== null ? __('Record the request') : __('Send the request') }}</button></div>
    </div>
</form>
