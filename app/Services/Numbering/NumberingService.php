<?php

namespace App\Services\Numbering;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\DB;

/**
 * Gapless document numbers (spec §4): "PI-2026-0001", per series and year; party and product
 * codes ("C-0001") run without a year. The increment is one atomic statement inside the
 * caller's transaction, so a rolled-back document gives its number back and two people never
 * get the same one. The only writer of number_sequences.
 */
class NumberingService
{
    /** Series => the business_settings column that holds its prefix. */
    public const SERIES = [
        'client' => 'client_prefix',
        'supplier' => 'supplier_prefix',
        'shipping_company' => 'shipping_company_prefix',
        'product' => 'product_prefix',
        'pi' => 'pi_prefix',
        'supplier_order' => 'supplier_order_prefix',
        'shipment' => 'shipment_prefix',
        'payment' => 'payment_prefix',
        'journal' => 'journal_prefix',
        'employee' => 'employee_prefix',
        'payroll' => 'payroll_prefix',
        'quotation' => 'quotation_prefix',
        'claim' => 'claim_prefix',
        'credit_note' => 'credit_note_prefix',
        'debit_note' => 'debit_note_prefix',
        'sourcing' => 'sourcing_prefix',
        'expense_claim' => 'expense_claim_prefix',
        'asset' => 'asset_prefix',
        'sample' => 'sample_prefix',
        'service_invoice' => 'service_invoice_prefix',
    ];

    /** Codes that run without a year. */
    private const YEARLESS = ['client', 'supplier', 'shipping_company', 'product', 'employee', 'asset'];

    /** The next number of $series; call inside the transaction that uses it. */
    public function next(string $series, ?\DateTimeInterface $on = null): string
    {
        $column = self::SERIES[$series] ?? throw new \InvalidArgumentException("Unknown number series [{$series}].");
        $prefix = BusinessSetting::current()->{$column};
        $yearless = in_array($series, self::YEARLESS, true);
        $year = $yearless ? 0 : (int) ($on ?? now())->format('Y');

        $number = (int) DB::selectOne(
            'INSERT INTO number_sequences (series, year, last_number) VALUES (?, ?, 1)
             ON CONFLICT (series, year) DO UPDATE SET last_number = number_sequences.last_number + 1
             RETURNING last_number',
            [$series, $year],
        )->last_number;

        $digits = str_pad((string) $number, 4, '0', STR_PAD_LEFT);

        return $yearless ? "{$prefix}-{$digits}" : "{$prefix}-{$year}-{$digits}";
    }
}
