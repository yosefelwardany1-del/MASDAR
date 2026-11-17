<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Lookup;
use App\Models\Party;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\PostingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The journal: every entry, posted from documents and payments by rule or made by hand as an
 * adjustment. A manual entry is drafted with `accounting.edit` and posted or reversed with
 * `accounting.approve`. Written through PostingService.
 */
class JournalController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
            'status' => in_array($request->query('status'), ['draft', 'posted'], true) ? $request->query('status') : null,
            'event' => is_string($request->query('event')) && $request->query('event') !== '' ? $request->query('event') : null,
            'account_id' => ctype_digit((string) $request->query('account_id')) ? (int) $request->query('account_id') : null,
            'search' => trim((string) $request->query('search', '')),
        ];

        $entries = JournalEntry::query()
            ->withSum('lines', 'debit')
            ->with('reversal:id,reverses_entry_id,number', 'reverses:id,number')
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('entry_date', '<=', $d))
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['event'], fn ($q, $e) => $e === 'manual' ? $q->whereNull('event') : $q->where('event', $e))
            ->when($filters['account_id'], fn ($q, $id) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $id)))
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'ilike', '%'.$filters['search'].'%')
                ->orWhere('description', 'ilike', '%'.$filters['search'].'%')
                ->orWhereHas('lines', fn ($l) => $l->where('memo', 'ilike', '%'.$filters['search'].'%'))))
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('accounting.journal.index', [
            'entries' => $entries,
            'filters' => $filters,
            'events' => PostingService::EVENT_LABELS,
            'accounts' => Account::query()->where('is_postable', true)->orderBy('code')->get(),
        ]);
    }

    public function show(Request $request, JournalEntry $entry, Ledger $ledger)
    {
        $entry->load('lines.account', 'lines.party', 'creator', 'poster', 'reverses', 'reversal');

        return view('accounting.journal.show', [
            'entry' => $entry,
            'source' => $entry->sourceLink(),
            'canSee' => fn (?Party $party) => $ledger->canSeeParty($request->user(), $party),
            'debit' => $entry->lines->reduce(fn ($sum, $l) => \App\Services\Accounting\Decimal::add($sum, (string) $l->debit), '0'),
            'credit' => $entry->lines->reduce(fn ($sum, $l) => \App\Services\Accounting\Decimal::add($sum, (string) $l->credit), '0'),
        ]);
    }

    public function create(Ledger $ledger)
    {
        return view('accounting.journal.create', $this->formData(null, $ledger));
    }

    public function store(Request $request, PostingService $posting)
    {
        $data = $this->validated($request);

        try {
            $entry = $posting->createDraft($data, $request->user());
        } catch (AccountingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journal.show', $entry)->with('success', __('Draft entry saved.'));
    }

    public function edit(JournalEntry $entry, Ledger $ledger)
    {
        if ($entry->isPosted()) {
            return redirect()->route('accounting.journal.show', $entry)->with('error', __('A posted entry never changes; reverse it instead.'));
        }

        return view('accounting.journal.edit', $this->formData($entry->load('lines'), $ledger));
    }

    public function update(Request $request, JournalEntry $entry, PostingService $posting)
    {
        $data = $this->validated($request);

        try {
            $posting->updateDraft($entry, $data, $request->user());
        } catch (AccountingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journal.show', $entry)->with('success', __('Draft entry saved.'));
    }

    public function destroy(Request $request, JournalEntry $entry, PostingService $posting)
    {
        try {
            $posting->deleteDraft($entry, $request->user());
        } catch (AccountingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journal.index')->with('success', __('Draft entry deleted.'));
    }

    public function post(Request $request, JournalEntry $entry, PostingService $posting)
    {
        try {
            $entry = $posting->post($entry, $request->user());
        } catch (AccountingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journal.show', $entry)->with('success', __('Entry :number posted.', ['number' => $entry->number]));
    }

    public function reverse(Request $request, JournalEntry $entry, PostingService $posting)
    {
        $data = $request->validate([
            'reverse_on' => 'nullable|date_format:Y-m-d',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $reversal = $posting->reverse($entry, $request->user(), $data['reverse_on'] ?? null,
                filled($data['reason'] ?? null) ? __('Reversal of :number', ['number' => $entry->number]).' — '.$data['reason'] : null);
        } catch (AccountingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journal.show', $reversal)->with('success', __('Entry :number reversed by :reversal.', [
            'number' => $entry->number, 'reversal' => $reversal->number,
        ]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        // The lines table always offers a few empty rows: drop the ones left blank.
        $lines = array_values(array_filter((array) $request->input('lines', []), fn ($line) => is_array($line)
            && (filled($line['account_id'] ?? null) || filled($line['debit'] ?? null) || filled($line['credit'] ?? null))));
        $request->merge(['lines' => $lines]);

        $amount = ['nullable', 'regex:/^\d{1,14}(\.\d{1,4})?$/'];

        return $request->validate([
            'entry_date' => 'required|date_format:Y-m-d',
            'description' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.account_id' => 'required|integer|exists:accounts,id',
            'lines.*.party_id' => 'nullable|integer|exists:parties,id',
            'lines.*.memo' => 'nullable|string|max:255',
            'lines.*.currency' => ['nullable', 'string', 'size:3', Rule::in(array_keys(Lookup::options('currency')))],
            'lines.*.debit' => $amount,
            'lines.*.credit' => $amount,
            'lines.*.rate' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ], [
            'lines.required' => __('An entry needs at least one line.'),
            'lines.*.debit.regex' => __('Amounts are numbers with up to 4 decimals.'),
            'lines.*.credit.regex' => __('Amounts are numbers with up to 4 decimals.'),
            'lines.*.rate.regex' => __('Rates are numbers with up to 6 decimals.'),
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(?JournalEntry $entry, Ledger $ledger): array
    {
        $book = $ledger->bookCurrency();
        $lines = old('lines', $entry?->lines->map(fn ($l) => [
            'account_id' => $l->account_id,
            'party_id' => $l->party_id,
            'memo' => $l->memo,
            'currency' => $l->currency,
            'debit' => \App\Services\Accounting\Decimal::isPositive((string) $l->debit) ? (string) $l->amount_currency : '',
            'credit' => \App\Services\Accounting\Decimal::isPositive((string) $l->credit) ? (string) $l->amount_currency : '',
            'rate' => (string) $l->rate,
        ])->all() ?? []);

        return [
            'entry' => $entry,
            'lines' => array_values($lines) + array_fill(0, max(2, count($lines)), []),
            'accounts' => $ledger->postableAccounts(),
            'parties' => Party::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'currencies' => array_keys(Lookup::options('currency')) ?: [$book],
            'book' => $book,
            'rates' => $ledger->latestRates(),
        ];
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
