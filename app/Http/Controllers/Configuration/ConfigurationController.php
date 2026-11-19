<?php

namespace App\Http\Controllers\Configuration;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\Lookup;
use App\Models\OrderStepDefinition;
use App\Models\Payment;
use App\Services\Configuration\BusinessSettingsService;
use App\Services\Configuration\LookupService;
use App\Services\Configuration\OrderStepDefinitionService;
use App\Services\Numbering\NumberingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lists & options: where MASDAR answers the spec's OPEN questions themselves — the business
 * options, every editable list, and the steps of the order follow-up.
 */
class ConfigurationController extends Controller
{
    private const TABS = ['options', 'lists', 'steps'];

    /** Lists whose entries carry extra fields, and those fields. */
    private const META = [
        'container_type' => ['cbm' => 'Usable CBM', 'max_kg' => 'Maximum KG'],
        'port' => ['side' => 'Loading or discharge'],
        'leave_type' => ['days_per_year' => 'Days a year (blank: not counted)', 'paid' => 'Paid'],
        'client_stage' => ['from_documents' => 'Follows the documents', 'closed' => 'Not chased'],
    ];

    /** Yes/no list fields, and the answer when none is stored. */
    public const YES_NO_META = ['paid' => 1, 'from_documents' => 0, 'closed' => 0];

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'options';
        $list = array_key_exists((string) $request->query('list'), Lookup::LISTS) ? $request->query('list') : array_key_first(Lookup::LISTS);

        return view('configuration.index', [
            'tab' => $tab,
            'list' => $list,
            'lists' => Lookup::LISTS,
            'meta' => self::META[$list] ?? [],
            'entries' => Lookup::query()->ofList($list)->get(),
            'steps' => OrderStepDefinition::query()->ordered()->get(),
            'setting' => BusinessSetting::current(),
            'currencies' => Lookup::options('currency'),
            'containerStatuses' => Lookup::options('container_status'),
            'editable' => $request->user()->can('configuration.edit'),
        ]);
    }

    public function updateOptions(Request $request, BusinessSettingsService $service)
    {
        $currencies = array_keys(Lookup::options('currency'));
        $prefix = ['sometimes', 'required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'];
        $percent = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'];
        $data = $request->validate([
            'company_address' => 'nullable|string|max:500',
            'company_phone' => 'nullable|string|max:120',
            'pi_currency' => ['required', Rule::in($currencies)],
            'supplier_currency' => ['required', Rule::in($currencies)],
            'book_currency' => ['required', Rule::in($currencies)],
            'default_deposit_percent' => 'required|numeric|min:0|max:100',
            'pi_terms_template' => 'required|string|max:255',
            'step_warning_days' => 'required|integer|min:0|max:90',
            'eta_warning_days' => 'required|integer|min:0|max:90',
            // HR and payroll (spec §5.7); rates and bases stay empty until MASDAR gives them (Q-H3).
            'payroll_currency' => ['sometimes', 'required', Rule::in($currencies)],
            'hr_document_warning_days' => 'sometimes|required|integer|min:0|max:365',
            'payroll_days_per_month' => 'sometimes|required|numeric|min:1|max:31',
            'payroll_overtime_multiplier' => 'sometimes|required|numeric|min:1|max:5',
            'si_employee_percent' => $percent, 'si_employer_percent' => $percent,
            'hf_employee_percent' => $percent, 'hf_employer_percent' => $percent,
            'si_base_min' => 'sometimes|nullable|numeric|min:0',
            'si_base_max' => 'sometimes|nullable|numeric|min:0|gte:si_base_min',
            // The documents around the PI: quotations, the purchase contract, the commercial invoice.
            'quotation_valid_days' => 'sometimes|required|integer|min:1|max:365',
            'default_incoterm' => 'sometimes|required|string|max:60',
            'invoice_bank_details' => 'sometimes|nullable|string|max:2000',
            'purchase_contract_terms' => 'sometimes|nullable|string|max:10000',
            // Profit and ageing (spec §5.8): how a shared container's costs split (Q-C3), the age buckets.
            'container_cost_split' => 'sometimes|required|in:cbm,kg,value,cartons',
            'ageing_buckets' => ['sometimes', 'required', 'string', 'max:40', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3}){0,5}\s*$/'],
            // B32: the month the financial year starts in (on its 1st).
            'financial_year_start_month' => 'sometimes|required|integer|between:1,12',
        ] + array_fill_keys(array_values(NumberingService::SERIES), $prefix));
        if (isset($data['ageing_buckets'])) {
            $days = array_values(array_unique(array_filter(array_map('intval', explode(',', $data['ageing_buckets'])), fn (int $d) => $d > 0)));
            sort($days);
            if ($days === []) {
                throw ValidationException::withMessages(['ageing_buckets' => __('Enter the days of each bucket, e.g. 30,60,90.')]);
            }
            $data['ageing_buckets'] = implode(',', $days);
        }
        foreach (['si_employee_percent', 'si_employer_percent', 'hf_employee_percent', 'hf_employer_percent', 'si_base_min', 'si_base_max'] as $optional) {
            if (array_key_exists($optional, $data) && ($data[$optional] === null || $data[$optional] === '')) {
                $data[$optional] = null;
            }
        }
        // The working week: the ticked ISO weekdays (the form sends one empty value so that none can be ticked).
        if ($request->has('hr_work_days')) {
            $days = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('hr_work_days')), fn (int $d) => $d >= 1 && $d <= 7)));
            sort($days);
            if ($days === []) {
                throw ValidationException::withMessages(['hr_work_days' => __('Tick at least one working day.')]);
            }
            $data['hr_work_days'] = implode(',', $days);
        }

        foreach (['pi_issue_requires_approval', 'payments_out_require_approval', 'sales_see_supplier_prices', 'sales_own_clients_only', 'block_loading_unpaid', 'whatsapp_container_updates', 'client_portal_enabled', 'orders_from_issued_pi', 'payments_approved_by_another', 'part_cartons_allowed'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        // Which container statuses send clients a WhatsApp update (spec Q-C4).
        if ($request->has('whatsapp_update_statuses')) {
            // The form sends one empty value so that unticking every status clears them.
            $statuses = array_values(array_filter((array) $request->input('whatsapp_update_statuses'), 'filled'));
            validator(['statuses' => $statuses], ['statuses.*' => [Rule::in(array_keys(Lookup::options('container_status')))]])->validate();
            $data['whatsapp_update_statuses'] = implode(',', $statuses);
        }

        // B29: the account types each kind of payment may name as what the money is for.
        foreach (BusinessSetting::COUNTER_TYPE_SETTINGS as $kind => $column) {
            if ($request->has($column)) {
                $types = array_values(array_intersect(Account::TYPES, (array) $request->input($column)));
                if ($types === []) {
                    throw ValidationException::withMessages([$column => __('Tick at least one type of account for :kind.', ['kind' => Payment::kindLabel($kind)])]);
                }
                $data[$column] = implode(',', $types);
            }
        }

        $service->update($data);

        return redirect()->route('configuration.index', ['tab' => 'options'])->with('success', __('Options saved.'));
    }

    public function storeLookup(Request $request, LookupService $service)
    {
        $data = $request->validate([
            'list' => ['required', Rule::in(array_keys(Lookup::LISTS))],
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('lookups')->where('list', $request->input('list'))],
        ] + $this->lookupRules($request->input('list')));

        $service->create($this->lookupData($data));

        return redirect()->route('configuration.index', ['tab' => 'lists', 'list' => $data['list']])->with('success', __('Entry added.'));
    }

    public function updateLookup(Request $request, Lookup $lookup, LookupService $service)
    {
        $data = $request->validate($this->lookupRules($lookup->list));
        $data['active'] = $request->boolean('active');

        $service->update($lookup, $this->lookupData($data));

        return redirect()->route('configuration.index', ['tab' => 'lists', 'list' => $lookup->list])->with('success', __('Entry saved.'));
    }

    public function storeStep(Request $request, OrderStepDefinitionService $service)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', 'unique:order_step_definitions,code'],
        ] + $this->stepRules());
        $data['requires_attachment'] = $request->boolean('requires_attachment');

        $service->create($data);

        return redirect()->route('configuration.index', ['tab' => 'steps'])->with('success', __('Step added.'));
    }

    public function updateStep(Request $request, OrderStepDefinition $step, OrderStepDefinitionService $service)
    {
        $data = $request->validate($this->stepRules());
        $data['requires_attachment'] = $request->boolean('requires_attachment');
        $data['active'] = $request->boolean('active');

        $service->update($step, $data);

        return redirect()->route('configuration.index', ['tab' => 'steps'])->with('success', __('Step saved.'));
    }

    private function lookupRules(?string $list): array
    {
        $rules = [
            'name' => 'required|string|max:160',
            'name_ar' => 'nullable|string|max:160',
            'name_zh' => 'nullable|string|max:160',
            'sort' => 'required|integer|min:0|max:100000',
        ];
        foreach (array_keys(self::META[$list] ?? []) as $key) {
            $rules["meta_{$key}"] = match ($key) {
                'side' => 'nullable|in:loading,discharge',
                'paid', 'from_documents', 'closed' => 'nullable|in:0,1',
                default => 'nullable|numeric|min:0',
            };
        }

        return $rules;
    }

    /** Folds the meta_* inputs into the meta JSON. */
    private function lookupData(array $data): array
    {
        $meta = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'meta_')) {
                if ($value !== null && $value !== '') {
                    $meta[substr($key, 5)] = is_numeric($value) ? $value + 0 : $value;
                }
                unset($data[$key]);
            }
        }

        return $data + ['meta' => $meta];
    }

    private function stepRules(): array
    {
        return [
            'name' => 'required|string|max:160',
            'name_ar' => 'nullable|string|max:160',
            'name_zh' => 'nullable|string|max:160',
            'sort' => 'required|integer|min:0|max:100000',
            'kind' => 'required|in:manual,payment,receipt,booking,loading',
            'due_offset_days' => 'nullable|integer|min:-365|max:365',
        ];
    }
}
