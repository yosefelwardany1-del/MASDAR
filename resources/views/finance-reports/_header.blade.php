{{-- Report header: title, back to the list, print, and the CSV download of the current view.
     $title, $subtitle (optional), $csv (default true). --}}
<x-page-header :title="$title" :back="route('finance-reports.index')" :back-label="__('Financial reports')" :subtitle="$subtitle ?? null">
    <x-slot:actions>
        <button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i>{{ __('Print') }}</button>
        @if($csv ?? true)
            <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}" class="btn btn-light"><i class="bi bi-download" aria-hidden="true"></i>{{ __('Download CSV') }}</a>
        @endif
    </x-slot:actions>
</x-page-header>
