{{-- The Accounting screen's tabs. $active is the current tab key. --}}
<ul class="nav nav-tabs mb-3">
    @foreach([
        'chart' => ['accounting.index', __('Chart of accounts'), 'accounting.view'],
        'journal' => ['accounting.journal.index', __('Journal'), 'accounting.view'],
        'to-post' => ['accounting.to-post.index', __('To post'), 'accounting.edit'],
        'rules' => ['accounting.posting-rules.index', __('Posting rules'), 'accounting.view'],
        'periods' => ['accounting.periods.index', __('Periods'), 'accounting.view'],
    ] as $key => [$route, $label, $permission])
        @can($permission)
            <li class="nav-item"><a class="nav-link {{ $active === $key ? 'active' : '' }}" href="{{ route($route) }}">{{ $label }}</a></li>
        @endcan
    @endforeach
    @can('finance_reports.view')
        <li class="nav-item ms-auto"><a class="nav-link" href="{{ route('finance-reports.index') }}"><i class="bi bi-graph-up me-1"></i>{{ __('Financial reports') }}</a></li>
    @endcan
</ul>
