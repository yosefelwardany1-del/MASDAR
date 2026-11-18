{{-- Monthly columns, one series: $months (month Y-m, total, count), $book, $dense (optional: label every other month). Hover or focus a column for its value. --}}
@php
    use App\Services\Accounting\Decimal;
    $max = collect($months)->reduce(fn ($m, $r) => Decimal::cmp($r['total'], $m) > 0 ? $r['total'] : $m, '0');
    $label = fn (string $ym) => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $ym.'-01')->locale(app()->getLocale())->isoFormat('MMM YY');
@endphp
<div class="app-cols {{ ($dense ?? false) ? 'app-cols--dense' : '' }}">
    @if(Decimal::isPositive($max))<span class="app-cols__max app-num">{{ __('Highest :amount', ['amount' => Decimal::format($max, 0)]) }}</span>@endif
    <div class="app-cols__plot" role="list">
        @foreach($months as $m)
            @php $h = Decimal::isPositive($max) ? max(round((float) $m['total'] / (float) $max * 100, 1), Decimal::isPositive($m['total']) ? 1 : 0) : 0; @endphp
            <div class="app-cols__col" role="listitem" tabindex="0" aria-label="{{ $label($m['month']) }}: {{ Decimal::format($m['total']) }} {{ $book }}">
                <span class="app-cols__tip"><strong>{{ $label($m['month']) }}</strong> · <span class="app-num">{{ Decimal::format($m['total']) }} {{ $book }}</span> · {{ trans_choice('{0} no order|{1} one order|[2,*] :count orders', $m['count'], ['count' => $m['count']]) }}</span>
                <span class="app-cols__fill" style="block-size: {{ $h }}%"></span>
            </div>
        @endforeach
    </div>
    <div class="app-cols__axis" aria-hidden="true">@foreach($months as $m)<span>{{ $label($m['month']) }}</span>@endforeach</div>
</div>
