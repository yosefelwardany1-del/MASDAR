{{-- Where a container is, as a stepper of four phases: booked → loaded → at sea → arrived.
     Each phase starts at a status on the editable container_status list (booked, loaded_sealed,
     on_board, arrived); a phase whose status is not on the list is left out, and the exact
     status is always shown beside the stepper.
     Needs $statusSort (the current status's sort, or null), $sorts (code => sort), $flag (from
     ShippingQueries::etaFlag); optional $mode, $etd, $eta (Y-m-d strings), $reachedOn
     (status code => Y-m-d when the phase was first reached) and $compact. --}}
@php
    $mode = $mode ?? null;
    $etd = $etd ?? null;
    $eta = $eta ?? null;
    $reachedOn = $reachedOn ?? [];
    $compact = $compact ?? false;
    $atSea = ($mode === null || $mode === '' || str_starts_with((string) $mode, 'sea')) ? __('At sea') : __('On the way');
    $anchors = ['booked' => __('Booked'), 'loaded_sealed' => __('Loaded'), 'on_board' => $atSea, 'arrived' => __('Arrived')];
    $phases = collect($anchors)
        ->filter(fn ($label, $code) => isset($sorts[$code]))
        ->map(fn ($label, $code) => ['code' => $code, 'label' => $label, 'sort' => (int) $sorts[$code]])
        ->sortBy('sort')->values();
    $current = $statusSort === null || $statusSort === '' ? null : (int) $statusSort;
    $lastReached = $phases->filter(fn ($p) => $current !== null && $current >= $p['sort'])->keys()->last();
    $lastIndex = $phases->count() - 1;
    $steps = $phases->map(function ($p, $i) use ($current, $lastReached, $lastIndex, $flag, $etd, $eta, $reachedOn, $compact) {
        $reached = $current !== null && $current >= $p['sort'];
        $state = match (true) {
            $reached && $i === $lastReached && $i !== $lastIndex => 'current',
            $reached => 'done',
            $i === $lastIndex && isset($flag['late']) => 'late',
            $i === $lastIndex && isset($flag['soon']) => 'due',
            default => 'todo',
        };
        $estimate = match (true) {
            $reached => null,
            $p['code'] === 'on_board' && filled($etd) => ['ETD', $etd],
            $i === $lastIndex && filled($eta) => ['ETA', $eta],
            default => null,
        };

        return [
            'label' => $p['label'],
            'state' => $state,
            'date' => $reached ? ($reachedOn[$p['code']] ?? null) : (! empty($compact) && $estimate ? $estimate[1] : null),
            'hint' => $estimate ? new \Illuminate\Support\HtmlString(e(__($estimate[0])).' <span class="app-num app-fu-nowrap">'.e($estimate[1]).'</span>') : null,
        ];
    })->all();
@endphp
@if($steps)
    <x-stepper :steps="$steps" :compact="! empty($compact)" :label="__('Where the container is')" />
@endif
