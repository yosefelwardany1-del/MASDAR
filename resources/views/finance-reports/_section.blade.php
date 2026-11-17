{{-- One section of the profit and loss or the balance sheet, set out as a statement: the section's
     heading, each heading account as a label with its sub-accounts indented under it and closed
     by its total (when it has more than one), and the section's total with a rule above it.
     $title, $section (['rows' => ChartTree rows, 'total' => …]); $hideTotal (optional). --}}
@php
    use App\Services\Accounting\Decimal;

    $fig = fn ($v) => new \Illuminate\Support\HtmlString('<span class="app-num app-fig'.(Decimal::cmp((string) $v, '0') < 0 ? ' app-fig--neg' : '').'">'.e(Decimal::format($v)).'</span>');
    $rows = $section['rows'];

    // Each shown row's nearest shown ancestor, and how many shown children each row has.
    $children = [];
    $stack = [];
    foreach ($rows as $i => $r) {
        while ($stack && $rows[end($stack)]['depth'] >= $r['depth']) {
            array_pop($stack);
        }
        if ($stack) {
            $children[end($stack)] = ($children[end($stack)] ?? 0) + 1;
        }
        $stack[] = $i;
    }
    // When the chart has one top heading for the whole type (Assets under ASSETS), the section's
    // own heading and total stand for it; a type with several (Cost of sales, Operating
    // expenses) keeps each heading and its total.
    $minDepth = $rows ? min(array_column($rows, 'depth')) : 0;
    $top = array_keys(array_filter($rows, fn ($r) => $r['depth'] === $minDepth));
    $own = null;
    if (count($top) === 1 && $minDepth === 0 && ! $rows[$top[0]]['account']->is_postable && ($children[$top[0]] ?? 0) > 0) {
        $roots = \App\Models\Account::query()->where('type', $rows[$top[0]]['account']->type)->whereNull('parent_id')->count();
        $own = $roots === 1 ? $top[0] : null;
    }
    $offset = $minDepth + ($own !== null ? 1 : 0);

    $lines = [];
    $open = [];
    $close = function () use (&$open, &$lines, $rows, $children, $own, $offset) {
        $h = array_pop($open);
        if ($h !== $own && ($children[$h] ?? 0) > 1) {
            $lines[] = ['type' => 'subtotal', 'row' => $rows[$h], 'depth' => $rows[$h]['depth'] - $offset];
        }
    };
    foreach ($rows as $i => $r) {
        while ($open && $rows[end($open)]['depth'] >= $r['depth']) {
            $close();
        }
        $heading = ! $r['account']->is_postable && ($children[$i] ?? 0) > 0;
        if ($heading) {
            if ($i !== $own) {
                $lines[] = ['type' => 'group', 'row' => $r, 'depth' => $r['depth'] - $offset, 'single' => ($children[$i] ?? 0) === 1];
            }
            $open[] = $i;
        } else {
            $lines[] = ['type' => 'account', 'row' => $r, 'depth' => max(0, $r['depth'] - $offset)];
        }
    }
    while ($open) {
        $close();
    }
@endphp
<tr class="app-stmt__section"><th colspan="3" scope="rowgroup">{{ $title }}</th></tr>
@forelse($lines as $line)
    @php $account = $line['row']['account']; @endphp
    @if($line['type'] === 'group')
        <tr class="app-stmt__group">
            <td class="app-stmt__code"><span class="app-num">{{ $account->code }}</span></td>
            <td class="app-stmt__name" style="--depth: {{ $line['depth'] }}">{{ $account->displayName() }}</td>
            <td class="app-stmt__amt"></td>
        </tr>
    @elseif($line['type'] === 'subtotal')
        <tr class="app-stmt__subtotal">
            <td class="app-stmt__code"></td>
            <td class="app-stmt__name" style="--depth: {{ $line['depth'] }}">{{ __('Total :section', ['section' => $account->displayName()]) }}</td>
            <td class="app-stmt__amt">{{ $fig($line['row']['amount']) }}</td>
        </tr>
    @else
        <tr class="app-stmt__row {{ Decimal::isZero($line['row']['amount']) ? 'app-stmt__row--muted' : '' }}">
            <td class="app-stmt__code"><span class="app-num">{{ $account->code }}</span></td>
            <td class="app-stmt__name" style="--depth: {{ $line['depth'] }}">
                @can('finance_reports.view')
                    @if($account->is_postable && isset($ledgerFrom))
                        <a href="{{ route('finance-reports.account-ledger', array_filter(['account_id' => $account->id, 'from' => $ledgerFrom, 'to' => $ledgerTo ?? null])) }}">{{ $account->displayName() }}</a>
                    @else
                        {{ $account->displayName() }}
                    @endif
                @else
                    {{ $account->displayName() }}
                @endcan
            </td>
            <td class="app-stmt__amt">{{ $fig($line['row']['amount']) }}</td>
        </tr>
    @endif
@empty
    <tr class="app-stmt__empty"><td class="app-stmt__code"></td><td colspan="2">{{ __('Nothing posted.') }}</td></tr>
@endforelse
@unless($hideTotal ?? false)
    <tr class="app-stmt__total"><td class="app-stmt__code"></td><th scope="row">{{ __('Total :section', ['section' => $title]) }}</th><td class="app-stmt__amt">{{ $fig($section['total']) }}</td></tr>
@endunless
