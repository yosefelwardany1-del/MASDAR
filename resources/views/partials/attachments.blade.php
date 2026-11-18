{{-- Attachments panel. Requires $attachable (uses HasAttachments) and $type (its key in
     config('attachments.attachables')). The server enforces access; @can only hides controls. --}}
@php
    $screen = $attachable->attachmentModule();
    $items = $attachable->attachments;
    // The parent's own rule when it has one (an expense claim's receipts are its claimant's).
    $mayAttach = fn (string $level) => method_exists($attachable, 'attachmentAllows')
        ? $attachable->attachmentAllows(auth()->user(), $level)
        : auth()->user()->can($screen.'.edit');
@endphp
<div class="app-card p-3 mt-3">
    <div class="fw-semibold mb-2"><i class="bi bi-paperclip me-1"></i>{{ __('Attachments') }} <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $items->count() }}</span></div>
    @forelse($items as $att)
        <div class="d-flex align-items-center gap-3 py-2 border-bottom">
            <i class="bi {{ $att->iconClass() }} fs-4 text-secondary"></i>
            <div class="flex-grow-1">
                <div>{{ $att->title ?: $att->file_name }}</div>
                <div class="text-muted small"><span class="app-ltr">{{ $att->humanSize() }}</span> · {{ $att->uploader?->name ?? '—' }} · {{ $att->created_at?->diffForHumans() }}</div>
            </div>
            <a href="{{ route('attachments.download', $att) }}" class="btn btn-sm btn-light" title="{{ __('Download') }}"><i class="bi bi-download"></i></a>
            @if($mayAttach('delete'))
                <form action="{{ route('attachments.destroy', $att) }}" method="POST" onsubmit="return confirm(@js(__('Delete this attachment?')));">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-light text-danger" title="{{ __('Delete') }}"><i class="bi bi-trash"></i></button>
                </form>
            @endif
        </div>
    @empty
        <p class="text-muted small mb-2">{{ __('No documents attached yet.') }}</p>
    @endforelse

    @if($mayAttach('edit'))
        <form action="{{ route('attachments.store', ['type' => $type, 'id' => $attachable->getKey()]) }}" method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end mt-3">
            @csrf
            <input type="file" name="files[]" class="form-control form-control-sm" style="max-inline-size:340px" multiple required accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx">
            <button class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i>{{ __('Upload') }}</button>
            @error('files.*')<div class="w-100 text-danger small">{{ $message }}</div>@enderror
            @error('files')<div class="w-100 text-danger small">{{ $message }}</div>@enderror
        </form>
    @endif
</div>
