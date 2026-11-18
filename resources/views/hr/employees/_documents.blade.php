@php use App\Models\Lookup; @endphp
<x-section :title="__('Personal documents')" icon="passport" :subtitle="__('Passport, residence permit, visa, contract … The newest of each kind is current; a renewal is added as a new one.')" flush>
    <h2 class="visually-hidden" id="documents-head">{{ __('Personal documents') }}</h2>
    @if($employee->documents->isEmpty())
        <x-empty icon="passport" :title="__('No documents yet.')" :text="__('Add the residence permit and passport first: they are watched for expiry.')" />
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th scope="col">{{ __('Document') }}</th><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Issued on') }}</th><th scope="col">{{ __('Expires') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach($employee->documents as $doc)
                    @php
                        $isCurrent = optional($current->get($doc->kind))->id === $doc->id;
                        $days = $doc->daysLeft();
                    @endphp
                    <tr class="{{ $isCurrent ? '' : 'text-muted' }}">
                        <td>{{ Lookup::label('employee_document', $doc->kind) }}@if($doc->note)<div class="small text-muted" dir="auto">{{ $doc->note }}</div>@endif</td>
                        <td><span class="app-ltr">{{ $doc->number ?: '—' }}</span></td>
                        <td><span class="app-num">{{ $doc->issued_on?->format('Y-m-d') ?? '—' }}</span></td>
                        <td><span class="app-num">{{ $doc->expires_on?->format('Y-m-d') ?? '—' }}</span></td>
                        <td>
                            @if(! $isCurrent)
                                <x-status tone="skip" icon="arrow-repeat">{{ __('Renewed') }}</x-status>
                            @elseif($days === null)
                                <x-status tone="done">{{ __('Current') }}</x-status>
                            @elseif($days < 0)
                                <x-status tone="late">{{ __('Expired') }}</x-status>
                            @elseif($days <= $warningDays)
                                <x-status tone="due" icon="hourglass-split">{{ trans_choice('{0} Expires today|{1} Expires tomorrow|[2,*] Expires in :count days', $days, ['count' => $days]) }}</x-status>
                            @else
                                <x-status tone="done">{{ __('Valid') }}</x-status>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @can('employees.edit')
        <form method="POST" action="{{ route('employees.documents.store', $employee) }}" enctype="multipart/form-data" class="app-hr-inlineform border-top">
            @csrf
            <div class="app-hr-inlineform__title"><i class="bi bi-plus-circle" aria-hidden="true"></i>{{ __('Add a document or a renewal') }}</div>
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small" for="doc-kind">{{ __('Document') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <select name="kind" id="doc-kind" class="form-select @error('kind') is-invalid @enderror" required>
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach($documentKinds as $code => $name)<option value="{{ $code }}" @selected(old('kind') === $code)>{{ $name }}</option>@endforeach
                    </select>
                    @error('kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="doc-number">{{ __('Number') }}</label>
                    <input name="number" id="doc-number" dir="ltr" class="form-control" value="{{ old('number') }}" maxlength="80">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="doc-issued">{{ __('Issued on') }}</label>
                    <input type="date" name="issued_on" id="doc-issued" class="form-control" value="{{ old('issued_on') }}">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="doc-expires">{{ __('Expires') }}</label>
                    <input type="date" name="expires_on" id="doc-expires" class="form-control @error('expires_on') is-invalid @enderror" value="{{ old('expires_on') }}">
                    @error('expires_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label small" for="doc-files">{{ __('Scan') }}</label>
                    <input type="file" name="files[]" id="doc-files" class="form-control @error('files.*') is-invalid @enderror" multiple accept=".pdf,.jpg,.jpeg,.png,.webp">
                    @error('files.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="doc-note">{{ __('Note') }}</label>
                    <input name="note" id="doc-note" dir="auto" class="form-control" value="{{ old('note') }}">
                </div>
                <div class="col-md-2 d-grid align-items-end"><button class="btn btn-primary mt-md-4"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Add') }}</button></div>
            </div>
        </form>
    @endcan
</x-section>
