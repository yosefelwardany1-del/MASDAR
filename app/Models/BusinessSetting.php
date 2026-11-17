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
        'supplier_order_prefix' => 'SO',
        'shipment_prefix' => 'SH',
        'payment_prefix' => 'PAY',
        'journal_prefix' => 'JE',
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
        ];
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
    public function piTerms(string|float $depositPercent): string
    {
        $deposit = rtrim(rtrim(number_format((float) $depositPercent, 2, '.', ''), '0'), '.');
        $final = rtrim(rtrim(number_format(100 - (float) $depositPercent, 2, '.', ''), '0'), '.');

        return strtr($this->pi_terms_template, ['{deposit}' => $deposit, '{final}' => $final]);
    }
}
