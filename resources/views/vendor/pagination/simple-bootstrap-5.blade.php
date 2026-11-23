{{-- Published from Laravel (B31) so that every word is translated. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pages') }}">
        <ul class="pagination">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link">&laquo; {{ __('Previous page') }}</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                        &laquo; {{ __('Previous page') }}
                    </a>
                </li>
            @endif

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next page') }} &raquo;</a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true">
                    <span class="page-link">{{ __('Next page') }} &raquo;</span>
                </li>
            @endif
        </ul>
    </nav>
@endif
