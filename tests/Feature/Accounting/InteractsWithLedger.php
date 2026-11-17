<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\FxRate;
use App\Models\GoodsReceipt;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PiLine;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Security\Roles;
use App\Services\Accounting\LedgerReports;
use App\Services\Accounting\PostingService;
use Illuminate\Support\Str;

/**
 * Builders for the ledger tests. Payments, FX rates and goods receipts are inserted straight
 * into their tables: their own modules are built in parallel.
 */
trait InteractsWithLedger
{
    /** Messages are asserted in English, whatever translations the integrator merges. */
    protected function setUpInteractsWithLedger(): void
    {
        app()->setLocale('en');
    }

    protected function userWith(string $role): User
    {
        return User::factory()->create(['status' => 'active', 'locale' => 'en'])->assignRole($role);
    }

    protected function manager(): User
    {
        return $this->userWith(Roles::MANAGER);
    }

    protected function accountant(): User
    {
        return $this->userWith(Roles::ACCOUNTANT);
    }

    /** Switch off payments_out_require_approval (the Lists & options screen's writer is another module's). */
    protected function paymentsOutNeedNoApproval(): void
    {
        $setting = BusinessSetting::current();
        $setting->payments_out_require_approval = false;
        $setting->save();
    }

    protected function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    protected function posting(): PostingService
    {
        return app(PostingService::class);
    }

    /** SUM(debit − credit) of posted lines on an account, as a string. */
    protected function rawBalance(string $code): string
    {
        return app(LedgerReports::class)->movements()[$this->account($code)->id] ?? '0';
    }

    protected function payment(array $attributes = []): Payment
    {
        $kind = $attributes['kind'] ?? 'client_receipt';
        $money = $attributes['money_account_id'] ?? MoneyAccount::factory()->create()->id;
        $currency = MoneyAccount::query()->whereKey($money)->value('currency');

        return Payment::create($attributes + [
            'number' => 'PAY-T-'.Str::upper(Str::random(8)),
            'paid_on' => '2026-11-10',
            'kind' => $kind,
            'direction' => in_array($kind, Payment::KINDS_IN, true) ? 'in' : 'out',
            'money_account_id' => $money,
            'currency' => $currency,
            'amount' => '1000.0000',
            'rate' => $currency === 'USD' ? '1.000000' : '0.140000',
            'user_id' => User::factory()->create()->id,
        ])->fresh();
    }

    protected function fxRate(string $currency, string $date, string $rate): FxRate
    {
        return FxRate::create(['currency' => $currency, 'rate_date' => $date, 'rate' => $rate, 'created_by' => User::factory()->create()->id]);
    }

    /** A confirmed, current PI with two lines of 10 × 48 × 1.25 = 600 each (1,200 in all). */
    protected function confirmedPi(array $attributes = []): ProformaInvoice
    {
        $pi = ProformaInvoice::factory()->create($attributes);
        PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'line_no' => 1]);
        PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'line_no' => 2]);
        $pi->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $pi->created_by]);
        $pi->update(['status' => 'confirmed', 'confirmed_at' => '2026-11-05 10:00:00']);

        return $pi->fresh();
    }

    /** A CNY supplier order with lines of 10 cartons × 48 × 6.50 = 3,120 each; $received cartons per line. */
    protected function supplierOrder(int $lines = 2, ?int $received = 10): SupplierOrder
    {
        $order = SupplierOrder::factory()->create();
        for ($i = 0; $i < $lines; $i++) {
            $line = SupplierOrderLine::factory()->create(['supplier_order_id' => $order->id]);
            if ($received) {
                GoodsReceipt::create([
                    'supplier_order_line_id' => $line->id, 'received_on' => '2026-11-12', 'cartons' => $received,
                    'inspection_result' => 'pass', 'user_id' => $order->created_by,
                ]);
            }
        }

        return $order;
    }

    protected function shippingCompany(): Party
    {
        return Party::factory()->shippingCompany()->create();
    }

    /** A balanced manual draft: Dr $debit / Cr $credit, $amount in the book currency. */
    protected function draft(User $by, string $debit = '6501', string $credit = '1101', string $amount = '250', string $date = '2026-11-10')
    {
        return $this->posting()->createDraft(['entry_date' => $date, 'description' => 'Test', 'lines' => [
            ['account_id' => $this->account($debit)->id, 'debit' => $amount],
            ['account_id' => $this->account($credit)->id, 'credit' => $amount],
        ]], $by);
    }
}
