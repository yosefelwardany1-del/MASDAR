<?php

namespace App\Http\Controllers\Books;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FixedAsset;
use App\Services\Accounting\FixedAssetService;
use Illuminate\Http\Request;

/** The fixed asset register and its monthly depreciation (spec §5.8). */
class FixedAssetController extends Controller
{
    public function __construct(private FixedAssetService $service) {}

    public function index(Request $request)
    {
        $assets = FixedAsset::query()->with('assetAccount')->orderBy('code')->get();
        // B19: the months each asset still needs, oldest first; the form starts at the oldest.
        $missing = $assets->mapWithKeys(fn (FixedAsset $a) => [$a->id => $this->service->missingMonths($a)])->filter();
        $oldest = $missing->flatten()->min();
        $month = is_string($request->query('month')) && preg_match('/^\d{4}-\d{2}$/', $request->query('month')) ? $request->query('month') : ($oldest ?? now()->format('Y-m'));

        return view('fixed-assets.index', [
            'assets' => $assets,
            'month' => $month,
            'missing' => $missing,
            'charges' => $this->service->charges($month),
        ]);
    }

    public function create()
    {
        return view('fixed-assets.form', $this->formData() + ['asset' => null]);
    }

    public function store(Request $request)
    {
        $asset = $this->service->create($request->all(), $request->user());

        return redirect()->route('fixed-assets.show', $asset)->with('success', __('Asset :code added.', ['code' => $asset->code]));
    }

    public function show(FixedAsset $asset)
    {
        $asset->load(['depreciation', 'assetAccount', 'depreciationAccount', 'expenseAccount', 'attachments.uploader']);

        return view('fixed-assets.show', ['asset' => $asset]);
    }

    public function edit(FixedAsset $asset)
    {
        return view('fixed-assets.form', $this->formData() + ['asset' => $asset]);
    }

    public function update(Request $request, FixedAsset $asset)
    {
        $this->service->update($asset, $request->all(), $request->user());

        return redirect()->route('fixed-assets.show', $asset)->with('success', __('Asset updated.'));
    }

    public function dispose(Request $request, FixedAsset $asset)
    {
        $this->service->dispose($asset, (string) $request->input('disposed_on'), (string) $request->input('disposal_note'), $request->user());

        return redirect()->route('fixed-assets.show', $asset)->with('success', __('Asset marked as gone. Record any sale or write-off on the Journal screen.'));
    }

    public function depreciate(Request $request)
    {
        $month = (string) $request->input('month');
        $entry = $this->service->depreciate($month, $request->user());

        return redirect()->route('fixed-assets.index', ['month' => $month])->with('success', __('Depreciation for :month posted (:entry).', ['month' => $month, 'entry' => $entry->number]));
    }

    private function formData(): array
    {
        // B20: each list holds only the accounts of its kind.
        return [
            'assetAccounts' => $this->service->accountChoices('asset_account_id'),
            'contraAccounts' => $this->service->accountChoices('depreciation_account_id'),
            'expenseAccounts' => $this->service->accountChoices('expense_account_id'),
            'defaults' => [
                'asset_account_id' => Account::query()->where('code', '1801')->value('id'),
                'depreciation_account_id' => Account::query()->where('code', '1802')->value('id'),
                'expense_account_id' => Account::query()->where('code', '6601')->value('id'),
            ],
        ];
    }
}
