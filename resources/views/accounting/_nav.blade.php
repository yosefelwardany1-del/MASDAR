{{-- The Accounting screen's tabs. $active is the current tab key. --}}
<nav aria-label="{{ __('Accounting') }}">
    <ul class="nav nav-tabs app-subnav">
        @foreach([
            'chart' => ['accounting.index', __('Chart of accounts'), 'accounting.view', 'diagram-3'],
            'journal' => ['accounting.journal.index', __('Journal'), 'accounting.view', 'journal-text'],
            'to-post' => ['accounting.to-post.index', __('To post'), 'accounting.edit', 'inbox'],
            'rules' => ['accounting.posting-rules.index', __('Posting rules'), 'accounting.view', 'signpost-split'],
            'periods' => ['accounting.periods.index', __('Periods'), 'accounting.view', 'calendar3'],
        ] as $key => [$route, $label, $permission, $icon])
            @can($permission)
                <li class="nav-item"><a class="nav-link {{ $active === $key ? 'active' : '' }}" href="{{ route($route) }}" @if($active === $key) aria-current="page" @endif><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</a></li>
            @endcan
        @endforeach
        @can('finance_reports.view')
            <li class="nav-item app-subnav__end"><a class="nav-link" href="{{ route('finance-reports.index') }}"><i class="bi bi-graph-up" aria-hidden="true"></i>{{ __('Financial reports') }}</a></li>
        @endcan
    </ul>
</nav>
