{{-- Cash by currency: one horizontal bar per currency, sized by its value in the book currency at
     the rate of the day so currencies compare. The figure beside each bar is the amount itself;
     a negative one is red, hatched and keeps its minus sign.
     $inBook: currency => [amount, rate, book] (MoneyAccountController::inBook); $book: the book currency. --}}
@php
    use App\Services\Accounting\Decimal;
    use App\Services\Finance\MoneyFormat;

    $max = collect($inBook)->pluck('book')->filter(fn ($v) => $v !== null)->map(fn ($v) => Decimal::abs($v))
        ->reduce(fn ($carry, $v) => Decimal::cmp($v, $carry) > 0 ? $v : $carry, '0');
    $missing = collect($inBook)->contains(fn ($r) => $r['rate'] === null);
    $total = Decimal::sum(collect($inBook)->pluck('book')->filter(fn ($v) => $v !== null));
    $currencyNames = \App\Models\Lookup::options('currency');
@endphp
<ul class="app-hbars">
    @foreach($inBook as $currency => $row)
        @php
            $negative = Decimal::cmp($row['amount'], '0') < 0;
            $width = $row['book'] !== null && Decimal::isPositive($max) ? round((float) Decimal::abs($row['book']) / (float) $max * 100, 1) : 0;
        @endphp
        <li class="app-hbar {{ $negative ? 'app-hbar--neg' : '' }}">
            <span class="app-hbar__label">
                <span class="app-hbar__name"><span class="app-ltr">{{ $currency }}</span>@if(isset($currencyNames[$currency]) && $currencyNames[$currency] !== $currency) <span class="fw-normal text-muted">· {{ $currencyNames[$currency] }}</span>@endif</span>
                <span class="app-hbar__sub">
                    @if($row['rate'] === null)
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> {{ __('No FX rate yet') }}
                    @elseif($currency !== $book)
                        <span class="app-num">1 {{ $currency }} = {{ MoneyFormat::rate($row['rate']) }} {{ $book }}</span>
                    @else
                        {{ __('Book currency') }}
                    @endif
                </span>
            </span>
            <span class="app-hbar__track" aria-hidden="true"><span class="app-hbar__fill" style="inline-size: {{ $width }}%"></span></span>
            <span class="app-hbar__value">
                <span class="app-hbar__amount"><span class="app-num app-fig {{ $negative ? 'app-fig--neg' : '' }}">{{ MoneyFormat::amount($row['amount']) }}</span><span class="app-unit">{{ $currency }}</span></span>
                @if($row['book'] !== null && $currency !== $book)
                    <span class="app-hbar__hint">≈ <span class="app-num">{{ MoneyFormat::amount(Decimal::round($row['book'], 2)) }}</span> {{ $book }}</span>
                @endif
            </span>
        </li>
    @endforeach
</ul>
@if(count($inBook) > 1)
    <div class="app-hbars__foot">
        <span>{{ $missing ? __('Total of the currencies with a rate, in :book', ['book' => $book]) : __('All cash, in :book at the rate of the day', ['book' => $book]) }}</span>
        <span class="app-hbars__total">≈ <span class="app-num app-fig {{ Decimal::cmp($total, '0') < 0 ? 'app-fig--neg' : '' }}">{{ MoneyFormat::amount(Decimal::round($total, 2)) }}</span><span class="app-unit">{{ $book }}</span></span>
    </div>
@endif
