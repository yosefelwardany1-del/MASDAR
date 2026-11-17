{{-- One section of the profit and loss or the balance sheet: the accounts as a tree. --}}
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); @endphp
<tr class="table-light"><th colspan="3">{{ $title }}</th></tr>
@forelse($section['rows'] as $row)
    <tr class="{{ $row['account']->is_postable ? '' : 'fw-semibold' }}">
        <td class="app-num">{{ $row['account']->code }}</td>
        <td><span style="padding-inline-start: {{ $row['depth'] * 1.5 }}rem">{{ $row['account']->displayName() }}</span></td>
        <td class="app-num text-end">{{ $fmt($row['amount']) }}</td>
    </tr>
@empty
    <tr><td colspan="3" class="text-muted small">{{ __('Nothing posted.') }}</td></tr>
@endforelse
@unless($hideTotal ?? false)
    <tr class="fw-bold"><td></td><td>{{ __('Total :section', ['section' => $title]) }}</td><td class="app-num text-end">{{ $fmt($section['total']) }}</td></tr>
@endunless
