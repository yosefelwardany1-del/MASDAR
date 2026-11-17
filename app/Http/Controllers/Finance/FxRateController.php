<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\FxRate;
use App\Models\Lookup;
use App\Services\Finance\FxRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FX rates (screen `payments`, spec §4): one rate per currency per day, units of the book
 * currency for one unit of the currency. A payment's rate defaults to the latest one on or
 * before its date. Where the rates come from is OPEN (Q-A3): they are typed in.
 */
class FxRateController extends Controller
{
    public function __construct(private FxRateService $rates) {}

    public function index(Request $request)
    {
        $currency = preg_match('/^[A-Z]{3}$/', (string) $request->query('currency')) ? $request->query('currency') : null;

        return view('fx-rates.index', [
            'rates' => FxRate::query()->with('creator')
                ->when($currency, fn ($q, $c) => $q->where('currency', $c))
                ->orderByDesc('rate_date')->orderBy('currency')
                ->paginate(30)->withQueryString(),
            'currency' => $currency,
            'currencies' => $this->currencies(),
            'book' => BusinessSetting::current()->book_currency,
            // Read only, for the tiles: the latest rate of each currency.
            'latest' => collect(DB::select('SELECT DISTINCT ON (currency) currency, rate, rate_date FROM fx_rates ORDER BY currency, rate_date DESC')),
        ]);
    }

    public function create(Request $request)
    {
        return view('fx-rates.create', [
            'currencies' => $this->currencies(),
            'book' => BusinessSetting::current()->book_currency,
            'presetCurrency' => $request->query('currency'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'rate_date' => ['required', 'date'],
            'rate' => ['required', 'string', 'max:30'],
        ]);
        $rate = $this->rates->set($data['currency'], $data['rate_date'], trim($data['rate']), $request->user());

        return redirect()->route('fx-rates.index', ['currency' => $rate->currency])->with('success', __('Rate saved.'));
    }

    public function edit(FxRate $rate)
    {
        return view('fx-rates.edit', ['rate' => $rate, 'book' => BusinessSetting::current()->book_currency]);
    }

    public function update(Request $request, FxRate $rate)
    {
        $data = $request->validate(['rate' => ['required', 'string', 'max:30']]);
        $this->rates->set($rate->currency, $rate->rate_date, trim($data['rate']), $request->user());

        return redirect()->route('fx-rates.index', ['currency' => $rate->currency])->with('success', __('Rate saved.'));
    }

    /** @return array<string, string> code => name, without the book currency (always 1) */
    private function currencies(): array
    {
        return array_diff_key(Lookup::options('currency'), [BusinessSetting::current()->book_currency => true]);
    }
}
