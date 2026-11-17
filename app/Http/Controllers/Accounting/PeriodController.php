<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\PeriodService;
use Illuminate\Http\Request;

/** Accounting periods: nothing is posted into a closed one (I3). */
class PeriodController extends Controller
{
    public function index()
    {
        return view('accounting.periods.index', [
            'periods' => AccountingPeriod::query()->with('closer')->orderByDesc('starts_on')->get(),
        ]);
    }

    public function store(Request $request, PeriodService $service)
    {
        $data = $request->validate([
            'name' => 'required|string|max:40',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
        ]);

        try {
            $period = $service->create($data, $request->user());
        } catch (AccountingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.periods.index')->with('success', __('Period :name created.', ['name' => $period->name]));
    }

    public function close(Request $request, AccountingPeriod $period, PeriodService $service)
    {
        try {
            $service->close($period, $request->user());
        } catch (AccountingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.periods.index')->with('success', __('Period :name closed.', ['name' => $period->name]));
    }

    public function reopen(Request $request, AccountingPeriod $period, PeriodService $service)
    {
        try {
            $service->reopen($period, $request->user());
        } catch (AccountingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.periods.index')->with('success', __('Period :name reopened.', ['name' => $period->name]));
    }
}
