{{-- The CRM log of a party (spec §3.7), newest first. Append-only: nothing here edits or deletes
     an interaction; recording one needs crm.edit. Errors are in the "interaction" bag. --}}
@php
    $canCrm = auth()->user()->can('crm.edit');
    $iErrors = $errors->getBag('interaction');
    $useOld = $iErrors->any();
@endphp
<div class="app-card p-4 mt-3" id="crm">
    <h2 class="h6 mb-3"><i class="bi bi-chat-left-text me-1"></i>{{ __('CRM log') }}</h2>

    @if($canCrm)
        <form method="POST" action="{{ route('crm.interactions.store', $party) }}" class="mb-3 pb-3 border-bottom">
            @csrf
            <div class="row g-2">
                <div class="col-sm-6">
                    @include('parties._select', ['name' => 'channel', 'label' => __('Channel'), 'options' => $channels, 'value' => $useOld ? old('channel') : null, 'required' => true, 'bag' => 'interaction', 'id' => 'i-channel'])
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="i-when">{{ __('When') }} <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="happened_at" id="i-when" class="form-control {{ $iErrors->has('happened_at') ? 'is-invalid' : '' }}" value="{{ $useOld ? old('happened_at') : now()->format('Y-m-d\TH:i') }}" required>
                    @if($iErrors->has('happened_at'))<div class="invalid-feedback">{{ $iErrors->first('happened_at') }}</div>@endif
                </div>
                <div class="col-12">
                    <label class="form-label" for="i-summary">{{ __('Summary') }} <span class="text-danger">*</span></label>
                    <textarea name="summary" id="i-summary" rows="2" class="form-control {{ $iErrors->has('summary') ? 'is-invalid' : '' }}" required>{{ $useOld ? old('summary') : '' }}</textarea>
                    @if($iErrors->has('summary'))<div class="invalid-feedback">{{ $iErrors->first('summary') }}</div>@endif
                </div>
                <div class="col-sm-8">
                    <label class="form-label" for="i-next">{{ __('Next step') }}</label>
                    <input type="text" name="next_step" id="i-next" class="form-control {{ $iErrors->has('next_step') ? 'is-invalid' : '' }}" value="{{ $useOld ? old('next_step') : '' }}" maxlength="2000">
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="i-next-due">{{ __('Next step due') }}</label>
                    <input type="date" name="next_step_due" id="i-next-due" class="form-control {{ $iErrors->has('next_step_due') ? 'is-invalid' : '' }}" value="{{ $useOld ? old('next_step_due') : '' }}">
                    @if($iErrors->has('next_step_due'))<div class="invalid-feedback">{{ $iErrors->first('next_step_due') }}</div>@endif
                </div>
                <div class="col-12 form-text mt-0">{{ __('A next step with a due date also becomes a task for you.') }}</div>
            </div>
            <button class="btn btn-sm btn-primary mt-2"><i class="bi bi-journal-plus me-1"></i>{{ __('Record interaction') }}</button>
        </form>
    @endif

    @forelse($interactions as $i)
        <div class="py-2 border-bottom">
            <div class="d-flex flex-wrap gap-2 align-items-center small text-muted">
                <span class="app-pill">{{ $labels['interaction_channel'][$i->channel] ?? $i->channel }}</span>
                <span class="app-num">{{ $i->happened_at->format('Y-m-d H:i') }}</span>
                <span>· {{ $i->user?->name }}</span>
            </div>
            <div class="mt-1">{!! nl2br(e($i->summary)) !!}</div>
            @if($i->next_step)<div class="small mt-1"><i class="bi bi-arrow-return-left me-1"></i><span class="fw-semibold">{{ __('Next step') }}:</span> {{ $i->next_step }}</div>@endif
        </div>
    @empty
        <p class="text-muted small mb-0">{{ __('Nothing recorded yet.') }}</p>
    @endforelse
</div>
