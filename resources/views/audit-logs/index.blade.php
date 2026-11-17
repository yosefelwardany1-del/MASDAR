@extends('layouts.app')

@section('title', __('Audit log'))

@php $rtl = \App\Http\Middleware\SetUserLocale::isRtl(); @endphp

@section('content')
<x-page-header :title="__('Audit log')" :subtitle="__('Who changed what, and when. Nothing here can be edited.')" />

<form method="GET" class="app-filterbar" role="search" aria-label="{{ __('Audit log') }}">
    <div class="app-filterbar__field app-filterbar__field--wide">
        <label class="form-label" for="a-user">{{ __('User') }}</label>
        <select name="user_id" id="a-user" class="form-select form-select-sm">
            <option value="">{{ __('All users') }}</option>
            @foreach($users as $u)
                <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__field">
        <label class="form-label" for="a-action">{{ __('Action') }}</label>
        <select name="action" id="a-action" class="form-select form-select-sm">
            <option value="">{{ __('All actions') }}</option>
            @foreach(['created', 'updated', 'deleted'] as $a)
                <option value="{{ $a }}" @selected(request('action') === $a)>{{ __('audit.'.$a) }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__actions">
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Filter') }}</button>
        @if(request('user_id') || request('action'))<a href="{{ route('audit-logs.index') }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>@endif
    </div>
</form>

<x-section :title="__('Changes')" icon="clock-history" flush>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th scope="col">{{ __('Time') }}</th><th scope="col">{{ __('User') }}</th><th scope="col">{{ __('Action') }}</th><th scope="col">{{ __('Record') }}</th><th scope="col">{{ __('Changes') }}</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="text-nowrap small text-muted"><span class="app-num">{{ $log->created_at?->format('Y-m-d H:i') }}</span></td>
                    <td class="text-nowrap">{{ $log->user?->name ?? __('System') }}</td>
                    <td class="text-nowrap"><span class="app-audit-action">{{ __('audit.'.$log->action) }}</span></td>
                    <td class="small text-nowrap"><span class="app-ltr">{{ class_basename($log->model_type) }} #{{ $log->model_id }}</span></td>
                    <td>
                        <div class="app-diff">
                            @foreach(array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))) as $field)
                                <div class="app-diff__row">
                                    @php $hasOld = array_key_exists($field, $log->old_values ?? []); $hasNew = array_key_exists($field, $log->new_values ?? []); @endphp
                                    <span class="app-diff__field app-ltr">{{ $field }}</span>
                                    @if($hasOld)
                                        <span class="app-diff__old app-ltr"><span class="visually-hidden">{{ __('Before') }}: </span>{{ json_encode($log->old_values[$field] ?? null, JSON_UNESCAPED_UNICODE) }}</span>
                                    @endif
                                    @if($hasOld && $hasNew)<i class="bi {{ $rtl ? 'bi-arrow-left' : 'bi-arrow-right' }} app-diff__arrow" aria-hidden="true"></i>@endif
                                    @if($hasNew)
                                        <span class="app-diff__new app-ltr"><span class="visually-hidden">{{ __('After') }}: </span>{{ json_encode($log->new_values[$field] ?? null, JSON_UNESCAPED_UNICODE) }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><x-empty icon="clock-history" :title="__('Nothing recorded yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())<div class="p-3 border-top">{{ $logs->links() }}</div>@endif
</x-section>
@endsection
