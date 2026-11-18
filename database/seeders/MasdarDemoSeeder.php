<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Security\Roles;
use App\Services\Accounting\PostingService;
use App\Services\Crm\CrmService;
use App\Services\Finance\FxRateService;
use App\Services\Finance\MoneyAccountService;
use App\Services\Finance\PaymentQueries;
use App\Services\Finance\PaymentService;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\LeaveService;
use App\Services\Hr\PayrollQueries;
use App\Services\Hr\PayrollService;
use App\Services\Parties\PartyService;
use App\Services\Products\ProductService;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use App\Services\Quotations\QuotationService;
use App\Services\Shipping\ContainerTrackingService;
use App\Services\Shipping\ShipmentService;
use App\Services\SupplierOrders\GoodsReceiptService;
use App\Services\SupplierOrders\SupplierOrderService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Example data to show MASDAR the system (local and staging only): clients, factories and a
 * forwarder, two PIs, their factory orders part-way through the follow-up, one container at
 * sea, the payments, and the journal entries for what is ready to post. Everything goes through the real writers, so it is audited and obeys
 * every rule. Run after DemoUsersSeeder. Refuses to run in production.
 */
class MasdarDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('MasdarDemoSeeder never runs in production.');
        }
        $manager = User::role(Roles::MANAGER)->first() ?? throw new RuntimeException('Run DemoUsersSeeder first.');
        $sales = User::role(Roles::SALES)->first() ?? $manager;
        $merch = User::role(Roles::MERCHANDISER)->first() ?? $manager;
        Auth::setUser($manager);

        $parties = app(PartyService::class);
        $clients = [
            $parties->create(['is_client' => true, 'name' => 'Gulf Home Trading LLC', 'country' => 'AE', 'city' => 'Dubai', 'account_manager_id' => $sales->id, 'default_destination' => 'Jebel Ali, UAE', 'source' => 'exhibition'], $manager),
            $parties->create(['is_client' => true, 'name' => 'Nile Kitchenware Co.', 'country' => 'EG', 'city' => 'Cairo', 'account_manager_id' => $sales->id, 'default_destination' => 'Alexandria, Egypt', 'source' => 'referral'], $manager),
        ];
        $factories = [
            $parties->create(['is_supplier' => true, 'name' => 'Yiwu Bright Housewares Factory', 'name_local' => '义乌明亮家居用品厂', 'country' => 'CN', 'city' => 'Yiwu'], $manager),
            $parties->create(['is_supplier' => true, 'name' => 'Jinhua Steelware Co., Ltd.', 'name_local' => '金华钢制品有限公司', 'country' => 'CN', 'city' => 'Jinhua'], $manager),
        ];
        $forwarder = $parties->create(['is_shipping' => true, 'name' => 'Ningbo Fast Forwarding', 'name_local' => '宁波快运货代', 'country' => 'CN', 'shipping_kind' => 'forwarder'], $manager);

        $pis = app(ProformaInvoiceService::class);
        $make = function ($client, array $lines, ?string $po) use ($pis, $sales, $manager) {
            $pi = $pis->createDraft(['client_id' => $client->id, 'po_number' => $po, 'po_date' => now()->subDays(10)->toDateString()], $sales);
            foreach ($lines as [$item, $ctn, $per, $price, $cost, $factory]) {
                $pis->addLine($pi, ['product' => 'new', 'description' => $item, 'unit' => 'pcs', 'cartons' => $ctn, 'qty_per_carton' => $per,
                    'unit_price' => $price, 'cbm_per_carton' => '0.0820', 'kg_per_carton' => '11.5', 'supplier_id' => $factory->id,
                    'supplier_price' => $cost, 'supplier_currency' => 'CNY'], $manager);
            }

            return $pis->issue($pi->fresh(), $manager);
        };
        $pi1 = $make($clients[0], [
            ['Glass storage jar 500ml', 120, 24, '0.875', '3.10', $factories[0]],
            ['Glass storage jar 1L', 80, 24, '1.150', '4.20', $factories[0]],
            ['Stainless steel lunch box', 100, 48, '1.250', '5.60', $factories[1]],
        ], 'GH-2026-077');
        $make($clients[1], [['Ceramic mug 350ml', 150, 36, '0.640', '2.30', $factories[0]]], 'NK-114');

        $orders = app(SupplierOrderService::class)->createFromPi($pi1, collect($factories)->map(fn ($f) => [
            'supplier_id' => $f->id,
            'lines' => $pi1->lines->where('supplier_id', $f->id)->mapWithKeys(fn ($l) => [$l->id => null])->all(),
            'confirmation_date' => now()->subDays(20)->toDateString(), 'delivery_date' => now()->addDays(6)->toDateString(),
            'currency' => 'CNY', 'merchandiser_id' => $merch->id,
        ])->all(), $manager);

        $accounts = app(MoneyAccountService::class);
        $usd = MoneyAccount::where('name', 'HSBC Hong Kong — USD')->first()
            ?? $accounts->create(['name' => 'HSBC Hong Kong — USD', 'kind' => 'bank', 'currency' => 'USD', 'location' => 'hong_kong', 'ledger_account_id' => Account::where('code', '1101')->value('id'), 'active' => true]);
        $rmb = MoneyAccount::where('name', 'Yiwu bank — RMB')->first()
            ?? $accounts->create(['name' => 'Yiwu bank — RMB', 'kind' => 'bank', 'currency' => 'CNY', 'location' => 'mainland', 'ledger_account_id' => Account::where('code', '1201')->value('id'), 'active' => true]);
        app(FxRateService::class)->set('CNY', now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(), '0.140000', $manager);

        $payments = app(PaymentService::class);
        // The client's 30% deposit (of 10,728.00 USD), then RMB for the factories: 1,400 USD → 10,000 CNY at 0.14.
        $payments->record(['kind' => 'client_receipt', 'paid_on' => now()->subDays(18)->toDateString(), 'money_account_id' => $usd->id, 'amount' => '3218.40', 'party_id' => $clients[0]->id, 'proforma_invoice_id' => $pi1->id, 'reference' => 'TT deposit'], $manager);
        $pis->confirm($pi1->fresh(), $manager);
        ['out' => $transferOut] = $payments->transfer(['from_account_id' => $usd->id, 'to_account_id' => $rmb->id, 'paid_on' => now()->subDays(18)->toDateString(), 'amount' => '1400.00', 'in_amount' => '10000.00', 'reference' => 'RMB for factory deposits'], $manager);
        if (! app(PaymentQueries::class)->isEffective($transferOut->fresh())) {
            $payments->approve($transferOut->fresh(), $manager);
        }
        $deposit = $payments->record(['kind' => 'supplier_payment', 'paid_on' => now()->subDays(17)->toDateString(), 'money_account_id' => $rmb->id, 'amount' => '5000.00', 'party_id' => $factories[0]->id, 'supplier_order_id' => $orders[0]->id, 'reference' => 'Deposit'], $manager);
        $payments->approve($deposit, $manager);

        // The first factory's goods are in, booked, loaded and at sea; the second is still in production.
        foreach ($orders[0]->lines as $line) {
            app(GoodsReceiptService::class)->receive($line, ['received_on' => now()->subDays(4)->toDateString(), 'cartons' => (string) $line->cartons, 'inspection_result' => 'pass'], $manager);
        }
        $shipment = app(ShipmentService::class)->create(['shipping_company_id' => $forwarder->id, 'mode' => 'sea_fcl', 'booking_no' => 'NGB-55831', 'port_of_loading' => 'NGB', 'port_of_discharge' => 'JEA', 'etd' => now()->subDays(2)->toDateString(), 'eta' => now()->addDays(2)->toDateString(), 'vessel' => 'MSC AURORA', 'voyage' => '246W', 'incoterm' => 'FOB Ningbo'], $manager);
        $container = app(ShipmentService::class)->addContainer($shipment, ['container_no' => 'MSKU7712345', 'container_type' => '40HQ', 'seal_no' => 'ML-449120'], $manager);
        app(ShipmentService::class)->bookOrder($shipment, $orders[0], $manager);
        foreach ($orders[0]->lines as $line) {
            app(ContainerTrackingService::class)->load($container, $line, (string) $line->cartons, now()->subDays(3)->toDateString(), $manager);
        }
        foreach (['loaded_sealed' => 3, 'gate_in' => 2, 'on_board' => 2] as $status => $daysAgo) {
            app(ContainerTrackingService::class)->recordStatus($container, ['status' => $status, 'happened_at' => now()->subDays($daysAgo)->toDateTimeString(), 'place' => 'Ningbo'], $manager);
        }

        // The accountant's queue, cleared: the confirmed PI invoiced, the received order billed, both payments posted,
        // so the journal and the reports have something to show.
        $posting = app(PostingService::class);
        $posting->postClientInvoice($pi1->fresh(), $manager);
        $posting->postSupplierBill($orders[0]->fresh(), $manager);
        Payment::query()->orderBy('id')->get()->each(fn (Payment $payment) => $posting->postPayment($payment, $manager));

        $this->hr($manager, $usd, $rmb);
        $this->crm($manager, $sales, $clients, $factories[0], $forwarder);
    }

    /**
     * The CRM (spec §5.1): new leads, a dormant client, contacts over the last weeks across
     * clients, a factory and the forwarder, and follow-ups (one overdue).
     *
     * @param  list<Party>  $clients
     */
    private function crm(User $manager, User $sales, array $clients, Party $factory, Party $forwarder): void
    {
        $parties = app(PartyService::class);
        $crm = app(CrmService::class);
        $leads = [
            $parties->create(['is_client' => true, 'name' => 'Riyadh Home Center', 'country' => 'SA', 'city' => 'Riyadh', 'account_manager_id' => $sales->id, 'stage' => 'lead', 'source' => 'social_media'], $manager),
            $parties->create(['is_client' => true, 'name' => 'Basra Trading House', 'country' => 'IQ', 'city' => 'Basra', 'account_manager_id' => $sales->id, 'stage' => 'inquiry', 'source' => 'website'], $manager),
            $parties->create(['is_client' => true, 'name' => 'Casablanca Bazaar SARL', 'country' => 'MA', 'city' => 'Casablanca', 'account_manager_id' => $sales->id, 'stage' => 'dormant', 'source' => 'referral'], $manager),
        ];
        $contact = fn (Party $party, string $channel, int $daysAgo, string $summary, ?string $next = null, ?int $dueIn = null) => $crm->recordInteraction($party, [
            'channel' => $channel, 'happened_at' => now()->subDays($daysAgo)->setTime(10, 30)->toDateTimeString(), 'summary' => $summary,
            'next_step' => $next, 'next_step_due' => $dueIn === null ? null : now()->addDays($dueIn)->toDateString(),
        ], $sales);

        $contact($clients[0], 'whatsapp', 2, 'Sent photos of the loading; client asked for the B/L draft.', 'Send the B/L draft', 1);
        $contact($clients[0], 'meeting', 40, 'Met at the Canton Fair booth: interested in glass storage jars and lunch boxes.');
        $contact($clients[1], 'call', 5, 'Discussed the 30% deposit for the mug order; they will pay next week.', 'Remind about the deposit', -2);
        $contact($leads[0], 'whatsapp', 1, 'Asked for a catalogue of kitchen storage; prefers prices in USD FOB Ningbo.', 'Send the catalogue and price list', 2);
        $contact($leads[1], 'email', 45, 'Inquiry for plastic hangers, 2 × 40HQ. Waiting for their target price.');
        $contact($factory, 'factory_visit', 8, 'Checked the final samples of the 1L jar; lids fit well. Packing photos promised by Friday.');
        $contact($forwarder, 'wechat', 3, 'Confirmed MSC AURORA ETD and the free days at Jebel Ali.');

        // HS codes for customs, and two quotations: one sent to the new lead, one being drafted.
        $products = app(ProductService::class);
        foreach (['Glass storage jar 500ml' => '7013.49', 'Glass storage jar 1L' => '7013.49', 'Stainless steel lunch box' => '7323.93', 'Ceramic mug 350ml' => '6912.00'] as $name => $hs) {
            if ($product = Product::where('name', $name)->first()) {
                $products->update($product, ['hs_code' => $hs]);
            }
        }
        $quotations = app(QuotationService::class);
        $sent = $quotations->create(['client_id' => $leads[0]->id, 'currency' => 'USD', 'incoterm' => 'FOB Ningbo',
            'terms' => '30% deposit, 70% before shipping. Prices for the quantities quoted.'], $sales);
        $quotations->addLine($sent, ['product_id' => Product::where('name', 'Glass storage jar 500ml')->value('id'), 'unit_price' => '0.92', 'moq' => '2400'], $sales);
        $quotations->addLine($sent, ['description' => "Bamboo lid storage canister 1.2L\nBorosilicate glass, bamboo lid with silicone ring", 'unit' => 'pcs',
            'qty_per_carton' => '24', 'unit_price' => '1.35', 'moq' => '1200', 'cbm_per_carton' => '0.0900', 'kg_per_carton' => '12'], $sales);
        $quotations->send($sent, $sales);
        $draft = $quotations->create(['client_id' => $leads[1]->id, 'currency' => 'USD'], $sales);
        $quotations->addLine($draft, ['description' => 'Plastic hanger, 42 cm, non-slip', 'unit' => 'pcs', 'qty_per_carton' => '200', 'unit_price' => '0.085', 'cartons' => '600'], $sales);
    }

    /**
     * HR (spec §5.7): the Yiwu office's staff linked to the demo users, their documents (one
     * residence permit about to expire), leave and attendance, and last month's payroll
     * approved, posted and paid from RMB that the owners put in.
     */
    private function hr(User $manager, MoneyAccount $usd, MoneyAccount $rmb): void
    {
        $hrUser = User::role(Roles::HR)->first() ?? $manager;
        $accountant = User::role(Roles::ACCOUNTANT)->first() ?? $manager;
        $people = app(EmployeeService::class);
        $staff = [];
        foreach ([
            ['Omar Haddad', null, 'sales', 'yiwu_office', 'EG', 'Account manager', '2024-03-01', '9800', '600', 'sales@example.test'],
            ['Lin Mei', '林梅', 'merchandising', 'yiwu_office', 'CN', 'Merchandiser', '2023-09-15', '7200', '500', 'merchandiser@example.test'],
            ['Zhang Wei', '张伟', 'quality', 'warehouse', 'CN', 'QC inspector', '2025-02-10', '6000', '300', 'warehouse_qc@example.test'],
            ['Wang Fang', '王芳', 'logistics', 'yiwu_office', 'CN', 'Logistics coordinator', '2024-06-03', '6800', '400', 'logistics@example.test'],
            ['Sara Nabil', null, 'finance', 'yiwu_office', 'EG', 'Accountant', '2024-01-08', '9000', '500', 'accountant@example.test'],
        ] as [$name, $local, $department, $location, $country, $job, $joined, $base, $allowances, $email]) {
            $employee = $people->create([
                'name' => $name, 'name_local' => $local, 'department' => $department, 'work_location' => $location, 'nationality' => $country,
                'job_title' => $job, 'joined_on' => $joined, 'employment_type' => 'full_time', 'salary_currency' => 'CNY',
                'user_id' => User::where('email', $email)->value('id'), 'phone' => '+86 138 0000 '.str_pad((string) (count($staff) + 1), 4, '0', STR_PAD_LEFT),
            ], $hrUser);
            $people->addSalary($employee, ['effective_from' => $joined, 'base_salary' => $base, 'allowances' => $allowances, 'note' => 'Starting salary'], $manager);
            $staff[$name] = $employee;
        }
        $people->addSalary($staff['Lin Mei'], ['effective_from' => '2025-01-01', 'base_salary' => '7800', 'allowances' => '500', 'note' => 'Yearly raise'], $manager);

        $people->addDocument($staff['Omar Haddad'], ['kind' => 'passport', 'number' => 'A29384756', 'issued_on' => '2022-05-10', 'expires_on' => '2029-05-09'], $hrUser);
        $people->addDocument($staff['Omar Haddad'], ['kind' => 'residence_permit', 'number' => 'ZJ-0045821', 'issued_on' => now()->subYear()->addDays(25)->toDateString(), 'expires_on' => now()->addDays(25)->toDateString()], $hrUser);
        $people->addDocument($staff['Sara Nabil'], ['kind' => 'residence_permit', 'number' => 'ZJ-0039117', 'issued_on' => now()->subMonths(5)->toDateString(), 'expires_on' => now()->addMonths(7)->toDateString()], $hrUser);
        $people->addDocument($staff['Lin Mei'], ['kind' => 'contract', 'number' => 'MS-HR-2023-07', 'issued_on' => now()->subYears(2)->addDays(40)->toDateString(), 'expires_on' => now()->addDays(40)->toDateString()], $hrUser);

        // Last month: approved leave (one day of it unpaid), an absence and some overtime.
        $leave = app(LeaveService::class);
        $attendance = app(AttendanceService::class);
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
        $monday = $lastMonth->copy()->next(Carbon::MONDAY)->addWeek();
        $leave->approve($leave->request($staff['Wang Fang'], ['leave_type' => 'annual', 'starts_on' => $monday->toDateString(), 'ends_on' => $monday->copy()->addDay()->toDateString(), 'reason' => 'Family visit'], $staff['Wang Fang']->user ?? $hrUser), $manager);
        $wednesday = $monday->copy()->addDays(2);
        $leave->approve($leave->request($staff['Zhang Wei'], ['leave_type' => 'unpaid', 'starts_on' => $wednesday->toDateString(), 'ends_on' => $wednesday->toDateString(), 'reason' => 'Personal'], $hrUser), $manager);
        $attendance->record($staff['Zhang Wei'], ['work_date' => $monday->copy()->addWeek()->toDateString(), 'status' => 'present', 'overtime_hours' => '6', 'note' => 'Container loading'], $hrUser);
        $attendance->record($staff['Lin Mei'], ['work_date' => $monday->copy()->addWeek()->addDay()->toDateString(), 'status' => 'half_day'], $hrUser);
        // This month: a late arrival, and a request waiting for a decision.
        $today = now()->startOfDay();
        $lateDay = $today->copy()->startOfMonth();
        while (! $lateDay->isWeekday()) {
            $lateDay->addDay();
        }
        if ($lateDay->lte($today)) {
            $attendance->record($staff['Omar Haddad'], ['work_date' => $lateDay->toDateString(), 'status' => 'late', 'note' => 'Traffic'], $hrUser);
        }
        $next = $today->copy()->next(Carbon::MONDAY)->addWeek();
        $leave->request($staff['Lin Mei'], ['leave_type' => 'annual', 'starts_on' => $next->toDateString(), 'ends_on' => $next->copy()->addDay()->toDateString(), 'reason' => 'Trip home'], $staff['Lin Mei']->user ?? $hrUser);

        // Last month's payroll: prepared by HR, tax entered, approved by the manager, posted by the accountant.
        $payroll = app(PayrollService::class);
        $run = $payroll->prepare(['period' => $lastMonth->format('Y-m'), 'currency' => 'CNY', 'pay_date' => $today->copy()->startOfMonth()->addDays(9)->toDateString()], $hrUser);
        foreach (['Omar Haddad' => '312.50', 'Sara Nabil' => '265.00', 'Lin Mei' => '118.40'] as $name => $tax) {
            $payroll->updateLine($run->lines()->where('employee_id', $staff[$name]->id)->sole(), ['income_tax' => $tax], $hrUser);
        }
        $run = $payroll->approve($run, $manager);
        $posting = app(PostingService::class);
        $posting->postPayroll($run, $accountant);

        // The owners put in USD; part of it is changed to RMB; the salaries are paid from RMB.
        $payments = app(PaymentService::class);
        $inject = $payments->record(['kind' => 'other_in', 'paid_on' => $lastMonth->toDateString(), 'money_account_id' => $usd->id, 'amount' => '10000.00',
            'counter_account_id' => Account::where('code', '2501')->value('id'), 'reference' => 'Owners\' funds'], $manager);
        ['out' => $out] = $payments->transfer(['from_account_id' => $usd->id, 'to_account_id' => $rmb->id, 'paid_on' => $lastMonth->copy()->addDays(2)->toDateString(),
            'amount' => '6300.00', 'in_amount' => '45000.00', 'reference' => 'RMB for salaries'], $manager);
        if (! app(PaymentQueries::class)->isEffective($out->fresh())) {
            $payments->approve($out->fresh(), $manager);
        }
        $net = app(PayrollQueries::class)->totals($run)['net'];
        $salaries = $payments->record(['kind' => 'salary_payment', 'paid_on' => min($today->toDateString(), $run->pay_date->toDateString()), 'money_account_id' => $rmb->id,
            'amount' => $net, 'payroll_run_id' => $run->id, 'reference' => 'Salaries '.$lastMonth->format('Y-m')], $manager);
        if (! app(PaymentQueries::class)->isEffective($salaries->fresh())) {
            $payments->approve($salaries->fresh(), $manager);
        }
        foreach (Payment::query()->whereNotIn('id', DB::table('journal_entries')->where('source_type', PostingService::SOURCE_PAYMENT)->select('source_id'))->orderBy('id')->get() as $payment) {
            $posting->postPayment($payment, $manager);
        }
    }
}
