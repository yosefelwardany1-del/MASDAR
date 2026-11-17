{{-- Report header: title, back to the list, the CSV download of the current view. --}}
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a href="{{ route('finance-reports.index') }}" class="small text-muted">{{ __('Financial reports') }}</a>
        <h1 class="h4 mb-0">{{ $title }}</h1>
    </div>
    @if($csv ?? true)
        <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}" class="btn btn-light"><i class="bi bi-download me-1"></i>{{ __('Download CSV') }}</a>
    @endif
</div>
