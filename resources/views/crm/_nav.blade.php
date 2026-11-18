{{-- The CRM's sections: $active = home | pipeline | activity | tasks. --}}
<nav aria-label="{{ __('CRM') }}">
    <ul class="nav nav-tabs app-subnav">
        @foreach(array_filter([
            'home' => [route('crm.index'), __('Overview'), 'bullseye'],
            'pipeline' => Gate::allows('clients.view') ? [route('crm.pipeline'), __('Pipeline'), 'kanban'] : null,
            'activity' => [route('crm.activity'), __('Activity'), 'chat-left-text'],
            'tasks' => [route('crm.tasks.index'), __('Follow-up tasks'), 'check2-square'],
        ]) as $key => [$href, $label, $icon])
            <li class="nav-item"><a class="nav-link {{ $active === $key ? 'active' : '' }}" href="{{ $href }}" @if($active === $key) aria-current="page" @endif><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</a></li>
        @endforeach
    </ul>
</nav>
