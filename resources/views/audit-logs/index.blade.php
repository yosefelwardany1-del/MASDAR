@extends('layouts.app')

@section('title', __('Audit log'))

@section('content')
<h1 class="h4 mb-3">{{ __('Audit log') }}</h1>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3">
    <select name="user_id" class="form-select" style="max-inline-size:240px">
        <option value="">{{ __('All users') }}</option>
        @foreach($users as $u)
            <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>
        @endforeach
    </select>
    <select name="action" class="form-select" style="max-inline-size:200px">
        <option value="">{{ __('All actions') }}</option>
        @foreach(['created', 'updated', 'deleted'] as $a)
            <option value="{{ $a }}" @selected(request('action') === $a)>{{ __('audit.'.$a) }}</option>
        @endforeach
    </select>
    <button class="btn btn-light">{{ __('Filter') }}</button>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>{{ __('Time') }}</th><th>{{ __('User') }}</th><th>{{ __('Action') }}</th><th>{{ __('Record') }}</th><th>{{ __('Changes') }}</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="text-nowrap small text-muted"><span class="app-ltr">{{ $log->created_at?->format('Y-m-d H:i') }}</span></td>
                    <td>{{ $log->user?->name ?? __('System') }}</td>
                    <td>{{ __('audit.'.$log->action) }}</td>
                    <td class="small"><span class="app-ltr">{{ class_basename($log->model_type) }} #{{ $log->model_id }}</span></td>
                    <td class="small">
                        @foreach(array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))) as $field)
                            <div>
                                <span class="text-muted app-ltr">{{ $field }}</span>:
                                <span class="app-ltr text-decoration-line-through text-muted">{{ json_encode($log->old_values[$field] ?? null, JSON_UNESCAPED_UNICODE) }}</span>
                                <i class="bi bi-arrow-left mx-1"></i>
                                <span class="app-ltr">{{ json_encode($log->new_values[$field] ?? null, JSON_UNESCAPED_UNICODE) }}</span>
                            </div>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="app-empty">{{ __('Nothing recorded yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())<div class="p-3">{{ $logs->links() }}</div>@endif
</div>
@endsection
