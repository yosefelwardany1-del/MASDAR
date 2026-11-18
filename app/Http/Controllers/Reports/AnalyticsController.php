<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Decimal;
use App\Services\Reports\AnalyticsQueries;
use App\Support\Access\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Sales and purchase analysis (screen `analytics`, spec §5.8). */
class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsQueries $analytics)
    {
        $user = $request->user();
        $date = fn ($v, $default) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $default;
        $to = $date($request->query('to'), now()->toDateString());
        $from = $date($request->query('from'), Carbon::parse($to)->subMonthsNoOverflow(11)->startOfMonth()->toDateString());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $sales = $analytics->sales($from, $to, $user);
        $seesPurchases = Visibility::canSeeSupplierPrices($user);
        $purchases = $seesPurchases ? $analytics->purchases($from, $to) : collect();
        $total = Decimal::sum($sales->pluck('book'));

        return view('analytics.index', [
            'from' => $from, 'to' => $to, 'book' => $analytics->bookCurrency(),
            'total' => $total,
            'orders' => $sales->count(),
            'average' => $sales->count() ? bcdiv($total, (string) $sales->count(), 2) : '0',
            'clients' => $sales->pluck('client_id')->unique()->count(),
            'byMonth' => $analytics->salesByMonth($sales, $from, $to),
            'byClient' => $analytics->top($sales, 'client_id', 'client'),
            'byPerson' => $analytics->top($sales, 'manager_id', 'manager'),
            'byProduct' => $analytics->salesByProduct($from, $to, $user),
            'seesPurchases' => $seesPurchases,
            'purchaseTotal' => Decimal::sum($purchases->pluck('book')),
            'byFactory' => $analytics->top($purchases, 'supplier_id', 'supplier'),
            'purchaseByMonth' => $seesPurchases ? $analytics->salesByMonth($purchases, $from, $to) : [],
            'funnel' => $analytics->quotationFunnel($from, $to, $user),
            'missingRates' => $analytics->missingRates,
        ]);
    }
}
