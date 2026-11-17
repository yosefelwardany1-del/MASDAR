# CLAUDE.md

Guidance for Claude Code (claude.ai/code) working in this repository.

## What this is

**MASDAR's ERP + CRM** for MASDAR FOR TRADING CO., LIMITED, a Hong Kong company sourcing
goods from Chinese factories for its clients from an office in Yiwu. It started as a copy of the
starter ERP (`yosefelwardany1-del/Empty-ERP` at `88da6fd`) — the platform layer (sign-in, roles
and screen permissions, audit log, settings, attachments, notifications, AI and WhatsApp
clients, import/export, backups) — and phase 1 adds the business: clients, suppliers and
shipping companies with a CRM log, products, Proforma Invoices, the supplier-order follow-up,
the container follow-up, payments and the ledger.

**The authority for this build is MASDAR's spec, `docs/spec/PROJECT.md`** (draft 0.1, not yet
signed off). Where anything here, in a plan, or in the code disagrees with the
spec, the spec wins. Items it marks `OPEN` are built configurable and fail loudly — never
guessed, never silently defaulted.

**When anything stops you, go back to the spec first**: settle the question from it and record
the reading; ask the user only when the spec is silent or contradicts itself on a choice that
is theirs.

Designs and build plans live in `docs/superpowers/specs/` and `docs/superpowers/plans/`.

## Starting a client

Where MASDAR stands on each step is marked; every `OPEN` step waits on MASDAR's spec.

1. Put the client's build spec in `docs/spec/PROJECT.md` (keep the template's headings).
   **Drafted** (0.1, 2026-11-17) from MASDAR's PI template, order follow-up sheet and phase-1
   note; not signed off. Its §11 lists the open questions.
2. Name it: `APP_NAME` (env), the company name, Arabic name and logo (Settings screen), the
   brand colours. **Done:** `APP_NAME=MASDAR`; the legal name and MASDAR's logo (read from
   their PI, `public/images/masdar-logo.png` and `masdar-mark.png`) are the defaults until one is
   uploaded on the Settings screen; navy and gold in `resources/css/theme.css`. **OPEN:** the
   Arabic name (the brand "MASDAR TRADING" stands in).
3. Regional defaults. **Done:** `APP_TIMEZONE=Asia/Shanghai` (the scheduler runs in China
   time); each user picks Arabic, English or Chinese. **OPEN:** `WHATSAPP_DEFAULT_COUNTRY_CODE`
   (still `20`; clients are abroad — spec Q-B3).
4. Roles. **Done:** spec §6's proposed roles, editable in the role editor (below).
5. Sub-projects. **Decided by the client (2026-11-17):** phase 1 is built at once, with every
   OPEN item configurable so it can be changed later — the plan is
   `docs/superpowers/plans/2026-11-17-phase-1-build.md`.

Starter fixes are pulled in from the `Empty-ERP` repository (add it as a remote named
`starter` and merge its `main`); MASDAR's own work never goes back to it.

## Commands

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoUsersSeeder   # local only: {role}@example.test / password
php artisan db:seed --class=MasdarDemoSeeder  # local only, after DemoUsersSeeder: example clients, PIs, orders, a container at sea
php artisan masdar:daily-alerts                # the morning summary (scheduled 08:10 China time)
php artisan serve                              # http://localhost:8000
php artisan queue:work                         # large imports run here
php artisan test            # PostgreSQL only (see phpunit.xml) — never SQLite
php artisan test --filter=RoleMatrixTest
./vendor/bin/pint           # before every commit
php artisan user:create someone@example.com "Name" --role=manager --generate
```

PHP needs the `bcmath` extension (money is computed with it; Docker and CI have it). Tests need a
local PostgreSQL 16 with role `erp` / password `erp` and a database `erp_test`
(CI runs the same in `.github/workflows/ci.yml`). The backup test runs a real `pg_dump`, so the
client must be 16 or newer.

## House rules (every client, unless its spec says otherwise)

Business data is built as **append-only tables and computed-never-stored balances**. Eloquent's
idioms push the other way, so:

- **One writer per table.** A table is written by exactly one service method. Nothing else
  calls `create()`, `update()` or `save()` on it.
- **No model events.** `withEvents(discover: false)` stays on; listeners are registered by
  hand in `AppServiceProvider`, where their order and reason are written down. No observers
  or `booted()` hooks that write data.
- **No soft deletes on ledger or stock tables.** Corrections are new rows.
- **No `save()` on an approved document.** Approved means immutable; a correction is a new
  document or a new row.
- **Invariants live in PostgreSQL** — triggers and CHECK constraints — **never only in PHP.**
  PHP checks are for good error messages; the database is the gate.
- Balances are never stored: every balance is `SUM()` over rows. Money and quantities are
  `numeric(18,4)` (FX `numeric(18,6)`), never float.

## Architecture

Laravel 12, PHP 8.3+ (8.4 in Docker), PostgreSQL 16 (Render Postgres in production; `render.yaml`
is the whole deployment: service, database and a disk for uploads, in Singapore), Blade + Bootstrap 5.3
bundled by Vite (no CDN). IBM Plex Sans Arabic; brand tokens `--app-primary` / `--app-accent`
in `resources/css/theme.css`, classes prefixed `app-`. Two CSS entries: `app.css` (Bootstrap
RTL) and `app-ltr.css` (Bootstrap LTR), the same `theme.css` on top; the layouts pick one by
language. Every UI string goes through `__()` with English keys, translated in `lang/ar.json`
and `lang/zh_CN.json`; each user picks their language (`users.locale`, `SetUserLocale`
middleware, the switcher in the user menu). Logical CSS properties only (`ms-`/`me-`,
`inline-size`). Printed documents (the PI, the packing list) are always English, as MASDAR's
own are.
Single company: there is no multi-company layer (a guard test fails on a `company_id` column).

### Screens, roles and permissions

- `config/screens.php` is the ONE screen registry. Each screen has a key, an English label
  (shown through `__()`), route, sidebar group, icon and its levels. It drives the sidebar AND
  the permissions.
- Permissions are `{screen}.{level}`, levels cumulative: `view` ⊂ `edit` ⊂ `approve`
  (`App\Security\Screens::grantsFor`). Screens marked `signed_in` need sign-in only.
- MASDAR's roles (`App\Security\Roles`, spec §6): `manager` (every screen), `sales`,
  `merchandiser` (order follow-up), `warehouse_qc`, `logistics`, `accountant`, `staff` (nothing
  until granted) and `system_admin`. `Roles::defaultGrants` gives each its screens when the role
  is created. A user may hold several: the union applies. **There is no `Gate::before` and no bypass role.** `system_admin` is the
  vendor's support staff: users, roles, settings, audit log — never business data or approvals.
- `RolesAndPermissionsSeeder` runs on every deploy: it creates what is missing and grants a
  role its defaults only when it creates the role. It never revokes an editor change.
- `RoleManagementGuard` holds the assignment rules: nobody edits their own roles or a role
  they hold; only a manager grants manager, only a system_admin grants system_admin; an actor
  must hold every permission a role carries; the last active manager and the last active
  user who can edit users cannot be removed.
- **Decide per client:** because an actor must hold every permission a role carries,
  `system_admin` (which holds no business screen) cannot assign a business role, or edit,
  deactivate or reset the password of anyone who holds one. That keeps the vendor's support
  account from minting an account with business-data access; a client who wants support to
  manage business staff decides so in its spec.
- Routes: `$pres($uri, $controller, $screen)` in `routes/web.php` expands to the resource
  routes with `can:{screen}.view|edit`. Each sub-project adds its own screens.

### Platform services

| Service | Where | Notes |
|---|---|---|
| Audit | `App\Support\Audit\AuditTrail::record()` | Called EXPLICITLY inside each writer's transaction (fail-closed). A client with a ledger may replace it with a trigger audit in PostgreSQL. |
| Settings | `App\Models\Setting::current()` | One row (`CHECK id = 1`); a read never writes. Company name, Arabic name, logo. |
| Attachments | `HasAttachments`, `AttachmentService`, `config/attachments.php` | Private disk; access = the parent's screen (`attachmentModule()`), or the parent's own rule when it has `attachmentAllows($user, $level)` (parties, PIs, supplier orders, containers, payments: the data rules below). |
| Notifications | `App\Services\Notifications\NotificationService::send()` | In-app only for now. |
| AI | `OpenRouterClient` (resolves to `AnthropicClient` when `ANTHROPIC_API_KEY` is set), `AiFeature::enabled()` | Drafts only. Features register in `config/ai.php`; none ship yet. |
| WhatsApp | `App\Services\WhatsApp\{WhatsAppClient,PhoneNumber}` | Env-only credentials; numbers without a country code take `WHATSAPP_DEFAULT_COUNTRY_CODE`, Arabic-Indic digits accepted. |
| Import/export | `App\Support\IO\*`, `config/io.php` `resources` | Two gates: `import_export.*` and the resource's own screen. The generic upsert is for tables WITHOUT a writer; a writer-owned table supplies `customImporter()`. |
| Backup | `backup:run` (02:15 daily), `config/backup.php` | Off-box only in production (S3-compatible and/or Google Drive). |
| Sign-in | `LoginController`, Google SSO behind `SSO_GOOGLE_ENABLED` (off) | Passwords always work while `SSO_PASSWORDS_RETIRED=false`. |

Guard tests in `tests/Feature/Guards/` fail on a `company_id` column, a float column, a stored
`balance`, a reference to removed code, a former client's name, a screen whose route does
not exist, or a UI string without its Arabic and Chinese translation (`TranslationsTest`, which
also fails when a translation drops a `:placeholder`; Chinese takes full-width punctuation).
`tests/Unit/NoProcessLevelMemoTest` fails on a function-level `static` in `app/`. Text the
system stores in English for a person to read later (a step's automatic note) is translated
where it is shown (`SupplierOrderStepEvent::displayNote`).

## MASDAR phase 1

Built 2026-11-17 to `docs/superpowers/plans/2026-11-17-phase-1-build.md`. Each module owns its
tables through one service; the others only read them or call that service.

| Module | Screens (route names) | Writers (`app/Services/…`) |
|---|---|---|
| Clients, suppliers, shipping companies, CRM | `clients.*`, `suppliers.*`, `shipping-companies.*`, `crm.*` | `Parties\PartyService`, `Crm\CrmService` |
| Products | `products.*`, `products.photo` | `Products\ProductService` |
| Proforma invoices | `proforma-invoices.*` (incl. `.print`, MASDAR's own layout, English) | `ProformaInvoices\ProformaInvoiceService` |
| Order follow-up | `supplier-orders.*` (the board, `.evaluation`), `goods-receipts.*` | `SupplierOrders\{SupplierOrderService,OrderStepService,GoodsReceiptService}` |
| Container follow-up | `containers.*` (the board, `.packing-list`), `shipments.*` | `Shipping\{ShipmentService,ContainerTrackingService}` |
| Money | `payments.*`, `money-accounts.*`, `fx-rates.*` | `Finance\{PaymentService,MoneyAccountService,FxRateService}` |
| Accounting | `accounting.*` (chart, rules, periods, journal, the posting queue), `finance-reports.*` | `Accounting\{PostingService,ChartOfAccountsService,PeriodService}` |
| Lists & options, home, alerts | `configuration.*`, `dashboard`, `masdar:daily-alerts` | `Configuration\*`, `Dashboard\AttentionQueries` (read-only) |

**Every OPEN item is a setting, not code.** `BusinessSetting::current()` (Lists & options →
Business options) holds currencies, the default deposit and PI terms, the approval switches, who
sees what, warning days and number prefixes. `Lookup::options($list)` holds every editable list
(units, currencies, container types with capacity, container statuses — whose order is the
forward path — ports, modes, countries …). `OrderStepDefinition` holds the follow-up board's
steps (S2's eleven to start). Posting rules and the chart are edited on the Accounting screen. The
starting values come from `MasdarReferenceSeeder`, which runs on every deploy and only adds
what is missing.

**Invariants held by PostgreSQL** (proven in `tests/Feature/Invariants`): an issued PI and its
lines never change (a revision supersedes it) and one revision is current; line totals are
generated columns; party and product codes never change; received cartons never go below zero or
below what is loaded; a load never exceeds what was received; a container's status only moves
forward along the list unless a correction names the event it corrects; logs (CRM, steps, date
changes, receipts, events, loads, payments, approvals) are append-only; a payment is in its
account's currency and a reversal repeats it; a journal entry posts only when balanced, to
postable accounts, outside a closed period, and never changes after.

**Rules shared across modules — use these, don't re-derive them:**
- Who sees what: `Support\Access\Visibility` (supplier identities and prices; sales limited to
  their own clients).
- When a payment counts: `Finance\PaymentQueries::whereEffective` / `isEffective`. Money in counts
  at once, money out once approved (while the switch is on), and a transfer counts as a whole.
  PI paid figures: `PaymentQueries::paidForPiNumber` (client receipts in the PI currency, all
  revisions).
- Steps completed from other modules: `OrderStepService::recordAutomatic`, and
  `reopenAutomatic` when that is undone. It only reopens a step that is done; a step a person
  marked not applicable stays so. Who completes what:
  - goods receipt: `receive_goods`;
  - shipping: `booking` and `loading` (unbooking from the last shipment and unloading reopen them);
  - supplier payments that count: every step of kind `payment`. Some paid completes the first
    of them, all paid completes them all, and only a reversal reopens them.
- Numbers: `Numbering\NumberingService::next($series)`, inside the writer's transaction.
- Cancelling a supplier order releases its PI lines (`supplier_order_lines.released_at`), so they
  can be ordered from another factory. "One live order per PI line" counts unreleased lines only.
- Client WhatsApp updates: `Shipping\ClientContainerUpdates` sends through the queued job
  `SendContainerUpdates`, after commit, and logs to `whatsapp_messages`, append-only. It is
  switched by `BusinessSetting::whatsapp_container_updates` and its statuses.
  `WhatsAppClient::sendTemplate` sends approved templates only.
- Attention (home page and `masdar:daily-alerts`): `Dashboard\AttentionQueries` covers late and
  due steps, containers late or arriving soon, PIs booked to ship but not paid in full, and tasks.

UI: the design system is `resources/css/theme.css`, built with the UI/UX Pro Max skill
(Swiss/minimal, dense, in MASDAR's navy and gold). Its shared page components are in
`resources/views/components/`: `x-page-header`, `x-status`, `x-stepper`, `x-progress`,
`x-detail-list`, `x-kpi`, `x-section` and `x-empty`. Each area's own styles are in
`resources/css/screens/*.css`. Build new screens from these; keep colours as tokens, use logical
properties, and keep text contrast at 4.5:1.

`tests/Feature/Integration/` holds the cross-module tests. `OrderToCashTest` runs one order
through every module. Keep it green.

