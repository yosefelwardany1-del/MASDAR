{{-- New follow-up task. Params: $users (id, name); $party (fixed party, or null) or $parties
     (a choice of the parties the user may open). Errors are in the "task" bag. --}}
@php $taskErrors = $errors->getBag('task'); $useOld = $taskErrors->any(); @endphp
<form method="POST" action="{{ route('crm.tasks.store') }}">
    @csrf
    <div class="row g-2">
        <div class="col-12">
            <label class="form-label small" for="task-title">{{ __('Task') }} <span class="text-danger">*</span></label>
            <input type="text" name="title" id="task-title" class="form-control form-control-sm {{ $taskErrors->has('title') ? 'is-invalid' : '' }}" value="{{ $useOld ? old('title') : '' }}" maxlength="255" required>
            @if($taskErrors->has('title'))<div class="invalid-feedback">{{ $taskErrors->first('title') }}</div>@endif
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="task-due">{{ __('Due date') }} <span class="text-danger">*</span></label>
            <input type="date" name="due_on" id="task-due" class="form-control form-control-sm {{ $taskErrors->has('due_on') ? 'is-invalid' : '' }}" value="{{ $useOld ? old('due_on') : today()->addDay()->toDateString() }}" required>
            @if($taskErrors->has('due_on'))<div class="invalid-feedback">{{ $taskErrors->first('due_on') }}</div>@endif
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="task-assignee">{{ __('Assigned to') }} <span class="text-danger">*</span></label>
            @php $assignee = (string) ($useOld ? old('assigned_to') : auth()->id()); @endphp
            <select name="assigned_to" id="task-assignee" class="form-select form-select-sm {{ $taskErrors->has('assigned_to') ? 'is-invalid' : '' }}" required>
                @foreach($users as $u)
                    <option value="{{ $u->id }}" @selected($assignee === (string) $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
            @if($taskErrors->has('assigned_to'))<div class="invalid-feedback">{{ $taskErrors->first('assigned_to') }}</div>@endif
        </div>
        @if(isset($party) && $party)
            <input type="hidden" name="party_id" value="{{ $party->id }}">
        @elseif(isset($parties))
            <div class="col-12">
                <label class="form-label small" for="task-party">{{ __('About') }}</label>
                @php $chosen = (string) ($useOld ? old('party_id') : ''); @endphp
                <select name="party_id" id="task-party" class="form-select form-select-sm">
                    <option value="">{{ __('No party') }}</option>
                    @foreach($parties as $p)
                        <option value="{{ $p->id }}" @selected($chosen === (string) $p->id)>{{ $p->code }} · {{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="col-12">
            <label class="form-label small" for="task-notes">{{ __('Notes') }}</label>
            <textarea name="notes" id="task-notes" rows="2" class="form-control form-control-sm">{{ $useOld ? old('notes') : '' }}</textarea>
        </div>
    </div>
    <button class="btn btn-sm btn-primary mt-2"><i class="bi bi-plus-lg me-1"></i>{{ __('Add task') }}</button>
</form>
