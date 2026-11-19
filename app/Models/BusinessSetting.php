<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MASDAR's business options — one row (CHECK id = 1), like Setting. Reading never writes: before
 * anybody saves, current() returns the defaults unsaved. Every value is an answer the spec left
 * OPEN, set on the Lists & options screen. Written only by
 * App\Services\Configuration\BusinessSettingsService.
 */
class BusinessSetting extends Model
{
    protected $guarded = ['id'];

    /** The defaults, as on MASDAR's Proforma Invoice letterhead (spec S1) and the migration. */
    protected $attributes = [
        'company_address' => 'Room 1608, Block B, Liandu Building, Futian Subdistrict, Yiwu, Zhejiang',
        'company_phone' => 'TEL:008613085650160-13173800074',
        'pi_currency' => 'USD',
        'supplier_currency' => 'CNY',
        'book_currency' => 'USD',
        'default_deposit_percent' => '30.00',
        'pi_terms_template' => '{deposit}% DEPOSIT {final}% FINAL PAYMENT BEFORE SHIPPING',
        'pi_issue_requires_approval' => true,
        'payments_out_require_approval' => true,
        'sales_see_supplier_prices' => false,
        'sales_own_clients_only' => true,
        'block_loading_unpaid' => false,
        'step_warning_days' => 2,
        'eta_warning_days' => 3,
        'client_prefix' => 'C',
        'supplier_prefix' => 'S',
        'shipping_company_prefix' => 'SC',
        'product_prefix' => 'P',
        'pi_prefix' => 'PI',
        'supplier_order_prefix' => 'PO',
        'shipment_prefix' => 'SH',
        'payment_prefix' => 'PAY',
        'journal_prefix' => 'JE',
        'whatsapp_container_updates' => false,
        'whatsapp_update_statuses' => 'on_board,arrived',
        'employee_prefix' => 'EMP',
        'payroll_prefix' => 'PR',
        'payroll_currency' => 'CNY',
        'hr_document_warning_days' => 60,
        'hr_work_days' => '1,2,3,4,5',
        'payroll_days_per_month' => '21.75',
        'payroll_overtime_multiplier' => '1.50',
        'crm_quiet_days' => 30,
        'quotation_prefix' => 'QT',
        'quotation_valid_days' => 30,
        'default_incoterm' => 'FOB',
        'container_cost_split' => 'cbm',
        'ageing_buckets' => '30,60,90',
        'claim_prefix' => 'CL',
        'credit_note_prefix' => 'CN',
        'debit_note_prefix' => 'DN',
        'sourcing_prefix' => 'RFQ',
        'expense_claim_prefix' => 'EXP',
        'asset_prefix' => 'FA',
        'sample_prefix' => 'SMP',
        'service_invoice_prefix' => 'INV',
        'client_portal_enabled' => false,
        'orders_from_issued_pi' => false,
    ];

    protected function casts(): array
    {
        return [
            'default_deposit_percent' => 'decimal:2',
            'pi_issue_requires_approval' => 'boolean',
            'payments_out_require_approval' => 'boolean',
            'sales_see_supplier_prices' => 'boolean',
            'sales_own_clients_only' => 'boolean',
            'block_loading_unpaid' => 'boolean',
            'step_warning_days' => 'integer',
            'eta_warning_days' => 'integer',
            'whatsapp_container_updates' => 'boolean',
            'client_portal_enabled' => 'boolean',
            'orders_from_issued_pi' => 'boolean',
            'hr_document_warning_days' => 'integer',
            'crm_quiet_days' => 'integer',
            'quotation_valid_days' => 'integer',
            'payroll_days_per_month' => 'decimal:2',
            'payroll_overtime_multiplier' => 'decimal:2',
            'si_employee_percent' => 'decimal:3',
            'si_employer_percent' => 'decimal:3',
            'hf_employee_percent' => 'decimal:3',
            'hf_employer_percent' => 'decimal:3',
            'si_base_min' => 'decimal:4',
            'si_base_max' => 'decimal:4',
        ];
    }

    /** The container_status codes that send a client a WhatsApp update (when switched on). */
    public function whatsappUpdateStatuses(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->whatsapp_update_statuses))));
    }

    /**
     * The purchase contract's terms until MASDAR writes its own (Lists & options): a starting
     * point, in English and Chinese, as factories read Chinese.
     */
    public const DEFAULT_PURCHASE_CONTRACT_TERMS = <<<'TXT'
        1. Quality: the goods match the confirmed sample and the specifications above. 质量：货物须与确认样品及上述规格一致。
        2. Packing and labels: export cartons; labels, marks and packaging as MASDAR instructs. 包装与标签：出口纸箱；标签、唛头及包装按 MASDAR 要求。
        3. Delivery: the goods are ready for inspection by the delivery date; any delay is told to MASDAR in writing at once. 交货：须在交货日期前备妥待验；如有延误须立即书面通知 MASDAR。
        4. Inspection: MASDAR or its agent inspects before loading; goods that fail are reworked or replaced at the seller's cost. 验货：装柜前由 MASDAR 或其代理验货；不合格货物由卖方承担费用返工或更换。
        5. Payment: as stated above; the balance once the goods pass inspection. 付款：按上述条款；尾款在验货合格后支付。
        6. Disputes are settled by friendly negotiation. 争议由双方友好协商解决。
        TXT;

    public function purchaseContractTerms(): string
    {
        return filled($this->purchase_contract_terms) ? (string) $this->purchase_contract_terms : self::DEFAULT_PURCHASE_CONTRACT_TERMS;
    }

    /** ISO weekdays (1 = Monday … 7 = Sunday) that are working days. @return list<int> */
    /** The ageing report's bucket limits in days, ascending: "30,60,90" gives [30, 60, 90]. */
    public function ageingBuckets(): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->ageing_buckets)), fn (int $d) => $d > 0)));
        sort($days);

        return $days === [] ? [30, 60, 90] : $days;
    }

    public function workDays(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->hr_work_days)), fn (int $d) => $d >= 1 && $d <= 7)));
    }

    /** True while no social insurance or housing fund rate is set (spec Q-H3): payroll warns. */
    public function contributionRatesMissing(): bool
    {
        return $this->si_employee_percent === null && $this->si_employer_percent === null
            && $this->hf_employee_percent === null && $this->hf_employer_percent === null;
    }

    public static function current(): self
    {
        if ($row = static::query()->find(1)) {
            return $row;
        }

        $fresh = new static;
        $fresh->id = 1;

        return $fresh;
    }

    /** The PI footer for a deposit percentage, e.g. "30% DEPOSIT 70% FINAL PAYMENT BEFORE SHIPPING". */
    public function piTerms(string|int $depositPercent): string
    {
        $deposit = bcadd((string) $depositPercent, '0', 2);
        $trim = fn (string $n) => rtrim(rtrim($n, '0'), '.');

        return strtr($this->pi_terms_template, ['{deposit}' => $trim($deposit), '{final}' => $trim(bcsub('100', $deposit, 2))]);
    }
}
