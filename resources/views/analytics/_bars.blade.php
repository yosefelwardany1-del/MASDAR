{{-- Horizontal bars, one series: $rows (label, total, count), $book, $link (optional fn(row): ?string). --}}
@php
    use App\Services\Accounting\Decimal;
    $max = collect($rows)->reduce(fn ($m, $r) => Decimal::cmp($r['total'], $m) > 0 ? $r['total'] : $m, '0');
@endphp
@if(count($rows) === 0)
    <div class="app-panel-empty"><i class="bi bi-bar-chart" aria-hidden="true"></i>{{ __('Nothing in these dates.') }}</div>
@else
    <ol class="app-hbars">
        @foreach($rows as $r)
            @php $width = Decimal::isPositive($max) ? round((float) $r['total'] / (float) $max * 100, 1) : 0; $href = isset($link) && $r['id'] !== null ? $link($r) : null; @endphp
            <li class="app-hbars__row {{ $r['id'] === null ? 'app-hbars__row--other' : '' }}" title="{{ $r['label'] }}: {{ Decimal::format($r['total']) }} {{ $book }} · {{ trans_choice('{1} one order|[2,*] :count orders', $r['count'], ['count' => $r['count']]) }}">
                <span class="app-hbars__label">@if($href)<a href="{{ $href }}"><bdi>{{ $r['label'] }}</bdi></a>@else<bdi>{{ $r['label'] }}</bdi>@endif</span>
                <span class="app-hbars__track" aria-hidden="true"><span class="app-hbars__bar" style="inline-size: {{ $width }}%"></span></span>
                <span class="app-hbars__value app-num">{{ Decimal::format($r['total']) }}</span>
            </li>
        @endforeach
    </ol>
@endif
