<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\Lookup;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Services\Accounting\PostingService;
use App\Services\Hr\HrCalendar;
use App\Services\Hr\PayrollQueries;
use App\Services\Hr\PayrollService;
use Illuminate\Http\Request;

/**
 * Payroll (spec §5.7): the monthly runs, a run with its lines, a line's form while the run is a
 * draft, the payslips. Preparing and editing need payroll.edit; approving and voiding
 * payroll.approve.
 */
class PayrollController extends Controller
{
    public function __construct(private PayrollService $service, private PayrollQueries $queries) {}

    public function index(Request $request)
    {
        $runs = PayrollRun::query()->withSum('lines', 'gross')->withSum('lines', 'net')->withCount('lines')
            ->orderByDesc('period')->orderByDesc('id')->paginate(24);
        $settings = BusinessSetting::current();

        return view('hr.payroll.index', [
            'runs' => $runs,
            'paid' => $runs->getCollection()->mapWithKeys(fn (PayrollRun $r) => [$r->id => $r->isApproved() ? $this->queries->paid($r) : null]),
            'currencies' => Lookup::options('currency'),
            'defaultCurrency' => $settings->payroll_currency,
            'nextMonth' => now()->subMonthNoOverflow()->format('Y-m'),
            'ratesMissing' => $settings->contributionRatesMissing(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'currency' => ['nullable', 'string', 'size:3'],
            'pay_date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $run = $this->service->prepare($data, $request->user());

        return redirect()->route('payroll.show', $run)->with('success', __('Payroll prepared as a draft: check each line, then send it for approval.'));
    }

    public function show(PayrollRun $run)
    {
        $run->load(['lines' => fn ($q) => $q->join('employees', 'employees.id', '=', 'payroll_lines.employee_id')
            ->orderBy('employees.name')->select('payroll_lines.*'), 'lines.employee', 'creator', 'approver', 'voider']);
        $settings = BusinessSetting::current();
        $entry = JournalEntry::query()->where('source_type', PostingService::SOURCE_PAYROLL)->where('source_id', $run->id)
            ->whereNull('reverses_entry_id')->orderByDesc('id')->first();

        return view('hr.payroll.show', [
            'run' => $run,
            'totals' => $this->queries->totals($run),
            'paid' => $run->isApproved() ? $this->queries->paid($run) : '0',
            'payments' => $run->payments()->with('moneyAccount', 'approval')->orderBy('id')->get(),
            'entry' => $entry,
            'ratesMissing' => $settings->contributionRatesMissing(),
            'hasHolidays' => HrCalendar::hasHolidaysFor($run->period->year),
        ]);
    }

    public function update(Request $request, PayrollRun $run)
    {
        $data = $request->validate(['pay_date' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->service->updateDraft($run, $data, $request->user());

        return redirect()->route('payroll.show', $run)->with('success', __('Saved.'));
    }

    public function recalculate(Request $request, PayrollRun $run)
    {
        $this->service->recalculate($run, $request->user());

        return redirect()->route('payroll.show', $run)->with('success', __('Lines rebuilt from salaries, attendance and leave.'));
    }

    public function editLine(PayrollRun $run, PayrollLine $line)
    {
        abort_unless($line->payroll_run_id === $run->id, 404);

        return view('hr.payroll.line', ['run' => $run, 'line' => $line->load('employee')]);
    }

    public function updateLine(Request $request, PayrollRun $run, PayrollLine $line)
    {
        abort_unless($line->payroll_run_id === $run->id, 404);
        $rules = array_fill_keys(PayrollLine::EDITABLE, ['nullable', 'string', 'max:30']) + ['note' => ['nullable', 'string', 'max:2000']];
        $this->service->updateLine($line, $request->validate($rules), $request->user());

        return redirect()->to(route('payroll.show', $run).'#line-'.$line->id)->with('success', __('Line saved for :name.', ['name' => $line->employee->name]));
    }

    public function approve(Request $request, PayrollRun $run)
    {
        $run = $this->service->approve($run, $request->user());

        return redirect()->route('payroll.show', $run)->with('success', __('Payroll :number approved. It can now be posted and paid.', ['number' => $run->number]));
    }

    public function void(Request $request, PayrollRun $run)
    {
        $this->service->void($run, $request->user(), $request->input('void_reason'));

        return redirect()->route('payroll.show', $run)->with('success', __('Payroll run voided. The month can be run again.'));
    }

    public function destroy(Request $request, PayrollRun $run)
    {
        $this->service->deleteDraft($run, $request->user());

        return redirect()->route('payroll.index')->with('success', __('Draft deleted.'));
    }

    /** Printable payslips: one employee's, or the whole run's. English with Chinese labels. */
    public function payslips(Request $request, PayrollRun $run)
    {
        $lines = $run->lines()->with('employee')->get()->sortBy(fn ($l) => $l->employee->name)->values();
        if ($request->filled('line')) {
            $lines = $lines->where('id', (int) $request->query('line'))->values();
            abort_if($lines->isEmpty(), 404);
        }

        return view('hr.payroll.payslips', ['run' => $run, 'lines' => $lines, 'company' => Setting::current()]);
    }
}
