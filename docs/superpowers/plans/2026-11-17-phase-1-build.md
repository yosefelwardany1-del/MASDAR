# MASDAR phase 1 — build plan

Built 2026-11-17 from `docs/spec/PROJECT.md` (draft 0.1). The client asked for the whole of
phase 1 at once, with every `OPEN` item built configurable ("we can change it later"). So each
open choice is a list entry, a business option or a role grant on a screen, never a hard-coded
guess. The foundation commit holds the whole schema, so the modules below can be built side by side.

## What is already in place (do not rebuild, do not edit unless your module owns it)

- **Schema:** `database/migrations/2026_11_17_00000{1..6}_*.php`. Every table of phase 1, with
  the spec's invariants held by PostgreSQL triggers and CHECKs (I1–I8). These are proven
  in `tests/Feature/Invariants/DatabaseInvariantsTest.php`. Read the migrations for your
  tables before writing code: the triggers decide what may change.
- **Models:** `app/Models/*.php`, with relations and casts. Each model's docblock names its only writer
  (a service class). Generated columns (`total_qty`, `amount`, `total_cbm`, `total_kg`,
  `book_amount`, `signed_amount`, `signed_book_amount`) are never written.
- **Factories:** `database/factories/` for Party (states client/supplier/shippingCompany),
  Product, ProformaInvoice (state issued), PiLine, SupplierOrder, SupplierOrderLine,
  Shipment, Container, MoneyAccount (state rmb) and CrmTask.
- **Reference data:** `database/seeders/MasdarReferenceSeeder.php` seeds the editable lists
  (`lookups`), the 11 order steps, the draft chart of accounts and the posting rules.
  `Lookup::options('unit')` gives code => name in the user's language;
  `Lookup::label('port', $code)` gives one name. Lists: unit, currency, container_type
  (meta cbm, max_kg), container_status (its sort order is the forward path), port (meta side),
  shipping_mode, shipping_company_kind, party_source, client_stage, interaction_channel, country.
- **Business options:** `BusinessSetting::current()` (one row, defaults unsaved) holds
  pi_currency, supplier_currency, book_currency, default_deposit_percent, `piTerms($deposit)`,
  pi_issue_requires_approval, payments_out_require_approval, sales_see_supplier_prices,
  sales_own_clients_only, block_loading_unpaid, step_warning_days, eta_warning_days, the
  document prefixes, company_address, company_phone. Company names and logo are in
  `Setting::current()` (`route('settings.logo')` serves the logo to any signed-in user).
- **Numbering:** `app(NumberingService::class)->next('pi'|'supplier_order'|'shipment'|'payment'|'journal'|'client'|'supplier'|'shipping_company'|'product')`.
  Call it inside the writer's transaction.
- **Visibility:** `App\Support\Access\Visibility::canSeeSupplierPrices($user)`,
  `::ownClientsOnly($user)`, and `::scopeClients($query, $user, $column = 'account_manager_id')`.
- **Shared writers:** `App\Services\Products\ProductService` (create, update, removePhoto) and
  `App\Services\SupplierOrders\OrderStepService` (`recordAutomatic($order, $stepCode, $user, $note, $paymentId)`,
  `record(...)`, `latestEvents($order)`, `isDone(...)`).
- **Screens and roles:** `config/screens.php` lists every screen, its route name and its levels.
  `app/Security/Roles.php` gives each role its default grants. Screen keys: clients, suppliers,
  shipping_companies, crm, products, proforma_invoices (view/edit/approve), supplier_orders,
  goods_receipts, containers, payments (view/edit/approve), accounting (view/edit/approve),
  finance_reports (view), configuration.
- **Routes:** each module has its own file `routes/modules/<module>.php`, required inside the
  `auth` group of `routes/web.php`, where `$pres($uri, $controller, $screen, except: [...])`
  is in scope. Every extra route carries `->middleware('can:{screen}.{level}')`. The route name
  in `config/screens.php` for your screen must exist.
- **Attachments:** registered types (`config/attachments.php`): parties, proforma-invoices,
  supplier-orders, containers, payments, journal-entries. Show the panel with
  `@include('partials.attachments', ['attachable' => $model, 'type' => 'supplier-orders'])`.
- **Languages:** every user picks Arabic (RTL), English or Chinese. All UI text goes through
  `__('English text')`. Never hard-code Arabic in a view or controller.

## House rules (CLAUDE.md, enforced by review)

- One writer per table: a service in `app/Services/<Area>/`. Controllers validate and call the
  service. Nothing else calls `create`, `update` or `save` on that table.
- Every write runs in `DB::transaction` and calls `AuditTrail::record()` inside it.
- No model events, observers or `booted()` hooks. No soft deletes. Corrections are new rows.
- Money and quantities are `numeric(18,4)` strings. Do sums in SQL or with `bcadd`/`bcmul`,
  never PHP floats. FX rates are `numeric(18,6)`.
- Balances are always computed (`SUM`), never stored. A guard test fails on a column named
  `*balance*`.
- New columns go in a **new** migration in your own number range
  (`2026_11_17_1N00xx_...`, N = your module number), on your own tables only.

## UI conventions

`@extends('layouts.app')`, `@section('title', __('...'))`, `@section('content')`. Copy the look
of `resources/views/users/index.blade.php` and `users/_form.blade.php`: an `.app-card` around a
`.table`, `.btn-primary` for the main action, `.form-label`/`.form-control`/`.form-select`, and
`@error` feedback. Use logical spacing only (`ms-`/`me-`/`ps-`/`pe-`, never `ml-`/`left`).

- Numbers sit in `<span class="app-num">` or `<td class="app-num">`.
- Status pills use `.app-pill` plus `--done`, `--late`, `--due` or `--skip`.
- Product photos use `.app-thumb`, and big numbers use `.app-stat`.
- Wide boards go inside `.table-responsive` with the `app-board` class.
- Show `@can('screen.edit')` buttons only to those allowed; the server enforces the rule anyway.

## Translations

Every new `__()` key gets an Arabic translation in **`lang/pending/<module>.json`** (a flat JSON
object, English key => Arabic). Do not edit `lang/ar.json`; the integrator merges the pending
files and adds Chinese.

## Tests

Tests go in `tests/Feature/<Module>/`, using `RefreshDatabase` and `Tests\Concerns\SeedsReference`.
Make users with `User::factory()->create()->assignRole(Roles::X)`. Run them only against your
own database:

```bash
DB_DATABASE=erp_test_N php artisan test tests/Feature/<Module>
```

If PostgreSQL refuses connections, run `service postgresql start` first. Never run the full
suite, `migrate` on the `erp` database, or `pint` on the whole repository. Run
`./vendor/bin/pint <your paths>` on your own files only.

For each screen, test that it is forbidden without the permission and that the index renders.
Test that each write lands and is audited, and that each rule refuses what it must. Do not
commit; the integrator commits.

## Modules

| N | Module | Owns |
|---|---|---|
| 1 | Parties, CRM, products | `PartyService`, `CrmService`, Products screen (uses `ProductService`), clients / suppliers / shipping-companies / crm / products screens, product photo route, import/export schemas for parties and products |
| 2 | Proforma invoices | `ProformaInvoiceService`, the PI screens, the printed PI |
| 3 | Supplier orders and order follow-up | `SupplierOrderService`, `OrderStepService` (extends it), `GoodsReceiptService`, the follow-up board, goods receipt screen, supplier evaluation |
| 4 | Shipping and container follow-up | `ShipmentService`, `ContainerTrackingService`, the container board, packing list |
| 5 | Money | `MoneyAccountService`, `FxRateService`, `PaymentService`, the payments screens |
| 6 | Accounting | `ChartOfAccountsService`, `PostingService`, `PeriodService`, the accounting screens, financial reports and statements |
| — | Integrator | Lists & options screen, dashboard, daily alerts, translations, the integration pass |

### Contracts between modules

- `route('products.photo', $product)` (module 1) streams a product's photo to any signed-in
  user, or returns 404. The PI print and follow-up board use it.
- `route('proforma-invoices.show', $pi)`, `route('supplier-orders.show', $order)`,
  `route('containers.show', $container)`, `route('payments.show', $payment)`,
  `route('clients.show', $party)`, `route('suppliers.show', $party)` and
  `route('shipping-companies.show', $party)` are the detail pages other modules link to.
- **PI paid amounts:** a client receipt links a PI revision by `payments.proforma_invoice_id`.
  The paid amount of a PI number is the `SUM(signed_amount)` of client_receipt payments in
  the PI's currency over all revisions of that number.
- A payment is **effective** when it is incoming, when `payments_out_require_approval` is
  off, or when it has a `payment_approvals` row. Only effective payments are posted to the
  ledger or counted as paid out.
- Steps completed elsewhere go through `OrderStepService::recordAutomatic`:
  - `receive_goods` when every line of the order is fully received (module 3);
  - `booking` when an order is put on a shipment (module 4);
  - `loading` when every received carton of the order is loaded (module 4).
