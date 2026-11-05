@extends('layouts.app')

@section('title', __('Import / Export'))

@section('content')
<h1 class="h4 mb-1">{{ __('Import / Export') }}</h1>
<p class="text-muted mb-3">{{ __('Download a template, export existing data, or bulk-import CSV / XLSX.') }}</p>

@if(session('success'))
<div class="alert alert-success">{{ session('success') }}</div>
@endif

@if(session('import_result'))
@php $r = session('import_result'); @endphp
<div class="alert alert-info">
    <strong>{{ __('Last import') }}:</strong>
    {{ __(':created created, :updated updated, :failed failed of :total rows.', ['created' => $r['created'], 'updated' => $r['updated'], 'failed' => $r['failed'], 'total' => $r['total_rows']]) }}
    @if(!empty($r['errors']))
    <ul class="mb-0 small mt-1">
        @foreach($r['errors'] as $e)<li>{{ __('Row :row', ['row' => $e['row']]) }}: {{ implode('; ', $e['messages']) }}</li>@endforeach
    </ul>
    @endif
    @if(!empty($r['notices']))
    <div class="small fw-semibold mt-2">{{ __('Notes') }} ({{ count($r['notices']) }})</div>
    <ul class="mb-0 small">
        @foreach($r['notices'] as $n)<li>{{ __('Row :row', ['row' => $n['row']]) }}: {{ implode('; ', $n['messages']) }}</li>@endforeach
    </ul>
    @endif
</div>
@endif

@if($resources->isEmpty())
<div class="app-card app-empty mb-3">{{ __('Nothing to import or export yet.') }}</div>
@endif

<div class="row g-3">
    @foreach($resources as $schema)
    <div class="col-md-6 col-xl-4">
        <div class="app-card h-100 p-3">
            <h2 class="h6 mb-3">{{ $schema->label() }}</h2>
            <div class="mb-2">
                <div class="small text-muted mb-1">{{ __('Template') }}</div>
                <a class="btn btn-sm btn-light" href="{{ route('io.template', $schema->key()) }}?format=csv"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
                <a class="btn btn-sm btn-light" href="{{ route('io.template', $schema->key()) }}?format=xlsx"><i class="bi bi-filetype-xlsx me-1"></i>XLSX</a>
            </div>
            <div class="mb-3">
                <div class="small text-muted mb-1">{{ __('Export') }}</div>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('io.export', $schema->key()) }}?format=csv"><i class="bi bi-download me-1"></i>CSV</a>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('io.export', $schema->key()) }}?format=xlsx"><i class="bi bi-download me-1"></i>XLSX</a>
            </div>
            @can($schema->permissionFor('edit'))
            @can('import_export.edit')
            <form method="POST" action="{{ route('io.import', $schema->key()) }}" enctype="multipart/form-data" class="io-import-form" data-preview="{{ route('io.preview', $schema->key()) }}">
                @csrf
                <div class="small text-muted mb-1">{{ __('Import') }} (CSV / XLSX)</div>
                <input type="file" name="file" class="form-control form-control-sm mb-2" accept="{{ $schema->uploadAccept() }}" required>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-light io-preview-btn">{{ __('Preview') }}</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i>{{ __('Import') }}</button>
                </div>
                <div class="io-preview-result small mt-2"></div>
            </form>
            @endcan
            @endcan
        </div>
    </div>
    @endforeach
</div>

<div class="app-card mt-4">
    <div class="p-3 pb-0"><h2 class="h6">{{ __('My recent imports') }}</h2></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>{{ __('Time') }}</th><th>{{ __('Resource') }}</th><th>{{ __('File') }}</th><th class="text-end">{{ __('Rows') }}</th><th class="text-end">{{ __('Created') }}</th><th class="text-end">{{ __('Updated') }}</th><th class="text-end">{{ __('Failed') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
                @forelse($history as $run)
                <tr>
                    <td class="small text-muted">{{ $run->created_at?->diffForHumans() }}</td>
                    <td><span class="app-ltr">{{ $run->resource }}</span></td>
                    <td class="small"><span class="app-ltr">{{ $run->filename }}</span></td>
                    <td class="text-end">{{ $run->total_rows }}</td>
                    <td class="text-end text-success">{{ $run->created_count }}</td>
                    <td class="text-end">{{ $run->updated_count }}</td>
                    <td class="text-end {{ $run->failed_count ? 'text-danger' : '' }}">{{ $run->failed_count }}</td>
                    <td><span class="badge text-bg-{{ $run->status === 'completed' ? 'success' : ($run->inProgress() ? 'secondary' : 'danger') }}">{{ __('import.'.$run->status) }}</span></td>
                </tr>
                @empty
                <tr><td colspan="8" class="app-empty">{{ __('No imports yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
@php
    $ioText = [
        'choose' => __('Choose a file first.'),
        'previewing' => __('Previewing…'),
        'failed' => __('Preview failed.'),
        'summary' => __(':created new, :updated updates, :failed errors (:total rows)'),
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
                var html = '<div class="text-success">' + ioFill(ioText.summary, { created: d.created, updated: d.updated, failed: d.failed, total: d.total_rows }) + '</div>';
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
