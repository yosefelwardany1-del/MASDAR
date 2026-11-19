@extends('layouts.app')

@section('title', __('Import / Export'))

@section('content')
<x-page-header :title="__('Import / Export')" :subtitle="__('Download a template, export existing data, or bulk-import CSV / XLSX.')" />

@if($errors->any())
    <div class="alert alert-danger" role="alert"><ul class="mb-0 ps-3">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
@endif

@if(session('import_result'))
@php $r = session('import_result'); @endphp
<div class="alert {{ $r['failed'] ? 'alert-warning' : 'alert-success' }} app-io-result" role="status">
    <div class="d-flex gap-2 align-items-start">
        <i class="bi {{ $r['failed'] ? 'bi-exclamation-triangle' : 'bi-check2-circle' }}" aria-hidden="true"></i>
        <div>
            <strong>{{ __('Last import') }}:</strong>
            {{ __(':created created, :updated updated, :failed failed of :total rows.', ['created' => $r['created'], 'updated' => $r['updated'], 'failed' => $r['failed'], 'total' => $r['total_rows']]) }}
            @if(!empty($r['errors']))
            <ul class="small">
                @foreach($r['errors'] as $e)<li>{{ __('Row :row', ['row' => $e['row']]) }}: {{ implode('; ', $e['messages']) }}</li>@endforeach
            </ul>
            @endif
            @if(!empty($r['notices']))
            <div class="small fw-semibold mt-2">{{ __('Notes') }} ({{ count($r['notices']) }})</div>
            <ul class="small">
                @foreach($r['notices'] as $n)<li>{{ __('Row :row', ['row' => $n['row']]) }}: {{ implode('; ', $n['messages']) }}</li>@endforeach
            </ul>
            @endif
        </div>
    </div>
</div>
@endif

@if($resources->isEmpty())
<div class="app-card mb-4"><x-empty icon="arrow-down-up" :title="__('Nothing to import or export yet.')" /></div>
@endif

<div class="app-io mb-4">
    @foreach($resources as $schema)
    <section class="app-card app-io__card" aria-labelledby="io-{{ $schema->key() }}">
        <h2 class="app-io__title" id="io-{{ $schema->key() }}"><i class="bi bi-table" aria-hidden="true"></i>{{ $schema->label() }}</h2>
        <div class="app-io__row">
            <div class="app-io__label">{{ __('Template') }}</div>
            <div class="app-io__btns">
                <a class="btn btn-sm btn-light" href="{{ route('io.template', $schema->key()) }}?format=csv"><i class="bi bi-filetype-csv" aria-hidden="true"></i>CSV</a>
                <a class="btn btn-sm btn-light" href="{{ route('io.template', $schema->key()) }}?format=xlsx"><i class="bi bi-filetype-xlsx" aria-hidden="true"></i>XLSX</a>
            </div>
        </div>
        <div class="app-io__row">
            <div class="app-io__label">{{ __('Export') }}</div>
            <div class="app-io__btns">
                <a class="btn btn-sm btn-outline-primary" href="{{ route('io.export', $schema->key()) }}?format=csv"><i class="bi bi-download" aria-hidden="true"></i>CSV</a>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('io.export', $schema->key()) }}?format=xlsx"><i class="bi bi-download" aria-hidden="true"></i>XLSX</a>
            </div>
        </div>
        @can($schema->permissionFor('edit'))
        @can('import_export.edit')
        <form method="POST" action="{{ route('io.import', $schema->key()) }}" enctype="multipart/form-data" class="io-import-form app-io__row app-io__import" data-preview="{{ route('io.preview', $schema->key()) }}">
            @csrf
            <label class="app-io__label" for="io-file-{{ $schema->key() }}">{{ __('Import') }} (CSV / XLSX)<span class="app-req" aria-hidden="true">*</span></label>
            <input type="file" name="file" id="io-file-{{ $schema->key() }}" class="form-control form-control-sm" accept="{{ $schema->uploadAccept() }}" required>
            <div class="app-io__btns">
                <button type="button" class="btn btn-sm btn-light io-preview-btn"><i class="bi bi-eye" aria-hidden="true"></i>{{ __('Preview') }}</button>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload" aria-hidden="true"></i>{{ __('Import') }}</button>
            </div>
            <div class="io-preview-result small" aria-live="polite"></div>
        </form>
        @endcan
        @endcan
    </section>
    @endforeach
</div>

<x-section :title="__('My recent imports')" icon="clock-history" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Time') }}</th><th scope="col">{{ __('Resource') }}</th><th scope="col">{{ __('File') }}</th><th scope="col" class="text-end">{{ __('Rows') }}</th><th scope="col" class="text-end">{{ __('Created') }}</th><th scope="col" class="text-end">{{ __('Updated') }}</th><th scope="col" class="text-end">{{ __('Failed') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>
                @forelse($history as $run)
                <tr>
                    <td class="small text-muted text-nowrap">{{ $run->created_at?->diffForHumans() }}</td>
                    <td><span class="app-ltr">{{ $run->resource }}</span></td>
                    <td class="small"><span class="app-ltr">{{ $run->filename }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $run->total_rows }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $run->created_count }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $run->updated_count }}</span></td>
                    <td class="text-end"><span class="app-num {{ $run->failed_count ? 'app-fig--neg fw-semibold' : '' }}">{{ $run->failed_count }}</span></td>
                    <td>
                        @php [$tone, $icon] = $run->status === 'completed' ? ['done', 'check-circle'] : ($run->inProgress() ? ['info', 'arrow-repeat'] : ['late', 'x-circle']); @endphp
                        <x-status :tone="$tone" :icon="$icon">{{ $run->statusLabel() }}</x-status>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="p-0"><x-empty icon="clock-history" :title="__('No imports yet.')" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection

@push('scripts')
@php
    $ioText = [
        'choose' => __('Choose a file first.'),
        'previewing' => __('Previewing…'),
        'failed' => __('Preview failed.'),
        'row' => __('Row :row'),
        'notes' => __('Notes'),
    ];
@endphp
<script>
// Escape HTML so reflected cell values / error messages cannot inject markup (XSS).
function ioEsc(v) {
    return String(v == null ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
var ioText = @json($ioText);
function ioFill(text, values) {
    return text.replace(/:(\w+)/g, function (m, k) { return k in values ? ioEsc(values[k]) : m; });
}
document.querySelectorAll('.io-preview-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var form = btn.closest('form');
        var fileInput = form.querySelector('input[type=file]');
        var out = form.querySelector('.io-preview-result');
        if (!fileInput.files.length) { out.innerHTML = '<span class="text-danger">' + ioEsc(ioText.choose) + '</span>'; return; }
        var fd = new FormData();
        fd.append('file', fileInput.files[0]);
        fd.append('_token', form.querySelector('input[name=_token]').value);
        out.textContent = ioText.previewing;
        fetch(form.dataset.preview, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                // A large file is previewed by a worker: say so rather than a fake result.
                if (d.queued) { out.textContent = d.message; return; }
                // Refused before any row (an unreadable file, a header not the template's): say why.
                if (d.errors && !Array.isArray(d.errors)) {
                    out.innerHTML = '<span class="text-danger">' + ioEsc([].concat.apply([], Object.values(d.errors)).join(' ')) + '</span>';
                    return;
                }
                var html = '<div class="text-success">' + ioEsc(d.summary) + '</div>';
                if (d.errors && d.errors.length) {
                    html += '<ul class="text-danger mb-0">';
                    d.errors.slice(0, 20).forEach(function (e) {
                        html += '<li>' + ioFill(ioText.row, { row: e.row }) + ': ' + (e.messages || []).map(ioEsc).join('; ') + '</li>';
                    });
                    html += '</ul>';
                }
                if (d.notices && d.notices.length) {
                    html += '<div class="fw-semibold mt-1">' + ioEsc(ioText.notes) + ' (' + ioEsc(d.notices.length) + ')</div><ul class="text-body-secondary mb-0">';
                    d.notices.slice(0, 30).forEach(function (n) {
                        html += '<li>' + ioFill(ioText.row, { row: n.row }) + ': ' + (n.messages || []).map(ioEsc).join('; ') + '</li>';
                    });
                    html += '</ul>';
                }
                out.innerHTML = html;
            })
            .catch(function () { out.innerHTML = '<span class="text-danger">' + ioEsc(ioText.failed) + '</span>'; });
    });
});
</script>
@endpush
