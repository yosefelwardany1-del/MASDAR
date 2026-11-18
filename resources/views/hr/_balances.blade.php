{{-- Leave balances: $balances from LeaveQueries::balances(). --}}
@php $fmtDays = fn ($d) => $d === null ? '—' : rtrim(rtrim((string) $d, '0'), '.'); @endphp
<div class="table-responsive">
    <table class="table align-middle mb-0 app-hr-balances">
        <thead><tr><th scope="col">{{ __('Kind of leave') }}</th><th scope="col" class="text-end">{{ __('A year') }}</th><th scope="col" class="text-end">{{ __('Taken') }}</th><th scope="col" class="text-end">{{ __('Waiting') }}</th><th scope="col" class="text-end">{{ __('Left') }}</th></tr></thead>
        <tbody>
        @foreach($balances as $b)
            <tr>
                <td>{{ $b['label'] }}@unless($b['paid'])<span class="app-pill app-pill--skip ms-1">{{ __('Unpaid') }}</span>@endunless</td>
                <td class="text-end"><span class="app-num">{{ $b['allowance'] === null ? __('Not counted') : $fmtDays($b['allowance']) }}</span></td>
                <td class="text-end"><span class="app-num">{{ $fmtDays($b['used']) }}</span></td>
                <td class="text-end"><span class="app-num">{{ bccomp($b['pending'], '0', 2) > 0 ? $fmtDays($b['pending']) : '—' }}</span></td>
                <td class="text-end">
                    @if($b['left'] === null)
                        <span class="text-muted">—</span>
                    @else
                        <span class="app-num fw-semibold {{ bccomp($b['left'], '0', 2) <= 0 ? 'text-danger' : '' }}">{{ $fmtDays($b['left']) }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
