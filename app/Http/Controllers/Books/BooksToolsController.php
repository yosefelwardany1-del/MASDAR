<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\Accounting\PostingService;
use App\Services\Accounting\Revaluation;
use Illuminate\Http\Request;

/** Exchange revaluation and opening balances, on the Accounting screen (spec §5.8). */
class BooksToolsController extends Controller
{
    public function revaluation(Request $request, Revaluation $revaluation, Ledger $ledger)
    {
        $date = is_string($request->query('date')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('date'))
            ? $request->query('date') : now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $error = null;
        $rows = [];
        try {
            $rows = $revaluation->preview($date);
        } catch (AccountingException $e) {
            $error = $e->getMessage();
        }

        return view('accounting.revaluation', ['date' => $date, 'rows' => $rows, 'error' => $error, 'book' => $ledger->bookCurrency()]);
    }

    public function postRevaluation(Request $request, PostingService $posting)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d']);
        $entry = $posting->postRevaluation($data['date'], $request->user());

        return redirect()->route('accounting.journal.show', $entry)->with('success', __('Revaluation posted (:entry).', ['entry' => $entry->number]));
    }

    public function opening(Ledger $ledger)
    {
        return view('accounting.opening', ['book' => $ledger->bookCurrency(), 'columns' => OpeningBalanceService::COLUMNS]);
    }

    public function openingTemplate()
    {
        $csv = implode(',', OpeningBalanceService::COLUMNS)."\n"
            ."2026-12-31,1101,,USD,25000.00,,HSBC USD balance\n"
            ."2026-12-31,1201,,CNY,180000.00,0.140000,Mainland bank RMB\n"
            ."2026-11-20,1401,C-0001,USD,7500.00,,PI-2026-0005 unpaid\n"
            ."2026-12-15,2201,S-0003,CNY,-42000.00,0.140000,PO-2026-0011 unpaid\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="opening-balances.csv"']);
    }

    public function importOpening(Request $request, OpeningBalanceService $service)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = null;
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $row = array_map(fn ($c) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $c)), $row);
            if ($header === null) {
                $header = array_map('strtolower', $row);

                continue;
            }
            if (count(array_filter($row, 'strlen')) === 0) {
                continue;
            }
            $rows[] = array_combine(OpeningBalanceService::COLUMNS, array_map(fn ($c) => $row[array_search($c, $header, true)] ?? '', OpeningBalanceService::COLUMNS));
        }
        fclose($handle);
        $drafts = $service->import($rows, $request->user());

        return redirect()->route('accounting.journal.index')->with('success', trans_choice('{1} One draft entry made: check it and post it.|[2,*] :count draft entries made, one per date: check and post them.', count($drafts), ['count' => count($drafts)]));
    }
}
