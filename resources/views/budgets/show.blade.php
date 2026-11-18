@extends('layouts.app')

@section('title', $budget->name.' '.$budget->year)

@php
    use App\Services\Accounting\Decimal;
    $f = fn ($v) => Decimal::format((string) $v, 0);
    $months = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Illuminate\Support\Carbon::create($budget->year, $m, 1)->locale(app()->getLocale())->isoFormat('MMM')]);
    $plain = fn ($v) => $v === null ? '' : (rtrim(rtrim((string) $v, '0'), '.') ?: '');
@endphp

@section('content')
<x-page-header :title="$budget->name.' · '.$budget->year" :back="route('budgets.index')" :back-label="__('Budgets')" :subtitle="__('Actual from posted entries; income and expenses both shown as positive figures.')">
    @can('budgets.edit')
        <x-slot:actions>
            @if($editing)
                <a href="{{ route('budgets.show', $budget) }}" class="btn btn-light">{{ __('Cancel') }}</a>
            @else
                <a href="{{ route('budgets.show', ['budget' => $budget, 'edit' => 1]) }}" class="btn btn-primary"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Enter amounts') }}</a>
            @endif
        </x-slot:actions>
    @endcan
</x-page-header>

@if($editing)
    <form method="POST" action="{{ route('budgets.update', $budget) }}" class="app-form">
        @csrf @method('PUT')
        <x-section :title="__('Amounts per month')" icon="grid-3x3" flush>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th scope="col" style="min-inline-size: 14rem">{{ __('Account') }}</th>@foreach($months as $m => $label)<th scope="col" class="text-end">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($accounts as $a)
                        <tr>
                            <th scope="row" class="fw-normal small">{{ $a->label() }}</th>
                            @foreach($months as $m => $label)
                                <td><input name="amounts[{{ $a->id }}][{{ $m }}]" value="{{ $plain($planned[$a->id][$m] ?? null) }}" inputmode="decimal" class="form-control form-control-sm text-end app-num" style="min-inline-size: 5.5rem" aria-label="{{ $a->code }} {{ $label }}"></td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-section>
        <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save the budget') }}</button></div>
    </form>
@else
    <form method="GET" class="app-filterbar app-filterbar--fit">
        <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="to_month">{{ __('Year to the end of') }}</label>
            <select id="to_month" name="to_month" class="form-select form-select-sm">@foreach($months as $m => $label)<option value="{{ $m }}" @selected($toMonth === $m)>{{ $label }}</option>@endforeach</select></div>
        <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Show') }}</button></div>
    </form>

    <div class="row g-3 mb-4 app-kpis">
        @foreach(['income' => __('Income'), 'expense' => __('Expenses')] as $key => $label)
            @php $t = $report['totals'][$key]; $diff = Decimal::sub($t['actual'], $t['budget']); $good = $key === 'income' ? ! str_starts_with($diff, '-') : str_starts_with($diff, '-') || Decimal::isZero($diff); @endphp
            <div class="col-6 col-lg-3"><x-kpi :label="$label.' · '.__('budget')" :value="$f($t['budget'])" icon="clipboard-data" /></div>
            <div class="col-6 col-lg-3"><x-kpi :label="$label.' · '.__('actual')" :value="$f($t['actual'])" icon="journal-check" :tone="$good ? 'done' : 'late'" :hint="__(':diff against budget', ['diff' => (Decimal::isPositive($diff) ? '+' : '').$f($diff)])" /></div>
        @endforeach
    </div>

    @foreach(['income' => __('Income'), 'expense' => __('Expenses')] as $key => $label)
        <x-section :title="$label" icon="{{ $key === 'income' ? 'arrow-down-left-circle' : 'arrow-up-right-circle' }}" flush class="mb-4">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 app-ledger">
                    <thead><tr><th scope="col">{{ __('Account') }}</th><th scope="col" class="text-end">{{ __('Budget') }}</th><th scope="col" class="text-end">{{ __('Actual') }}</th><th scope="col" class="text-end">{{ __('Difference') }}</th><th scope="col" style="min-inline-size: 10rem">{{ __('Used') }}</th></tr></thead>
                    <tbody>
                    @forelse($report[$key] as $r)
                        @php
                            $pct = Decimal::isPositive($r['budget']) ? round((float) $r['actual'] / (float) $r['budget'] * 100) : null;
                            $over = $key === 'expense' ? Decimal::cmp($r['actual'], $r['budget']) > 0 : Decimal::cmp($r['actual'], $r['budget']) < 0;
                        @endphp
                        <tr>
                            <td>{{ $r['account']->label() }}</td>
                            <td class="app-figcell"><span class="app-num app-fig">{{ $f($r['budget']) }}</span></td>
                            <td class="app-figcell"><span class="app-num app-fig">{{ $f($r['actual']) }}</span></td>
                            <td class="app-figcell"><span class="app-num app-fig {{ $over ? 'app-fig--neg' : '' }}">{{ $f($r['difference']) }}</span></td>
                            <td>@if($pct !== null)<span class="app-minibar {{ $over ? 'app-minibar--neg' : '' }}" aria-hidden="true"><span style="inline-size: {{ min(100, $pct) }}%"></span></span><span class="small app-num">{{ $pct }}%</span>@else<span class="small text-muted">{{ __('No budget') }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-0"><div class="app-panel-empty">{{ __('Nothing budgeted or posted yet.') }}</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-section>
    @endforeach
@endif
@endsection
