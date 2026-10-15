# CLAUDE.md

Guidance for Claude Code (claude.ai/code) working in this repository.

## What this is

**Alquds** is the ERP for Al-Quds (Cairo): publishing, outsourced printing, book export and
freight consolidation. Arabic RTL interface, English codebase. Single company — there is no
multi-company layer.

**The authority is `docs/spec/Alquds_Project.md`** (the client build spec). Where anything
here, in a plan, or in the code disagrees with it, the spec wins. Items it marks `OPEN` are
built configurable and fail loudly — never guessed, never silently defaulted.

The design and the build plans live in `docs/superpowers/specs/` and `docs/superpowers/plans/`.
The platform layer (sign-in, roles, audit, attachments, notifications, AI and WhatsApp clients,
import/export) was harvested from the INSHARO ERP; every business module is built to the spec.

## Commands

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoUsersSeeder   # local only: {role}@alquds.test / password
php artisan serve                              # http://localhost:8000
php artisan queue:work                         # large imports run here
php artisan test            # PostgreSQL only (see phpunit.xml) — never SQLite
php artisan test --filter=RoleMatrixTest
./vendor/bin/pint           # before every commit
php artisan user:create someone@example.com "Name" --role=manager --generate
```

Tests need a local PostgreSQL 16 with role `alquds` / password `alquds` and a database
`alquds_test` (CI runs the same in `.github/workflows/ci.yml`). The backup test runs a real
`pg_dump`, so the client must be 16 or newer.

## Standing rule (every sub-project)

This design is **append-only tables and computed-never-stored balances**. Eloquent's idioms
push the other way, so:

- **One writer per table.** A table is written by exactly one service method
  (`StockService::move()`, `LedgerService::post()`, `DocumentService::approve()` …). Nothing
  else calls `create()`, `update()` or `save()` on it.
- **No model events.** `withEvents(discover: false)` stays on; listeners are registered by
  hand in `AppServiceProvider`, where their order and reason are written down. No observers
  or `booted()` hooks that write data.
- **No soft deletes on ledger or stock tables.** Corrections are new rows (`reverses_id`).
- **No `save()` on an approved document.** Approved means immutable; a correction is a new
  document or a new row.
- **Invariants live in PostgreSQL** — triggers and CHECK constraints — **never only in PHP.**
  PHP checks are for good error messages; the database is the gate.
- Balances are never stored: every balance is `SUM()` over rows. Money and quantities are
  `numeric(18,4)` (FX `numeric(18,6)`), never float.

## Gates and flags

- **Sub-project 2 (invariants & kernel) gets a line-by-line human review on its own, before
  Sub-project 3 starts.** Never batched into an end-of-branch review — a ledger bug found in
  Sub-project 8 is expensive.
- **Sub-project 4 (packing-list demo) is assisted correction, not extraction.** The review
  screen is mandatory and always pre-filled; there is no blank-form entry path. Demo it
  against the real handwritten list, not a clean one. Track `edited_by_user` per line from
  the first version.
- **Sub-project 7 (costing) opens by flagging two client answers that do not exist yet:**
  which printers bill per signature vs per ream (`print_pricing_method`), and whether
  `pages_per_signature` is always 16. A missing rate-master key fails loudly — never default
  a price, margin or waste percentage silently.

## Architecture

Laravel 12, PHP 8.3+ (8.4 in Docker), PostgreSQL 16 (Neon in production), Blade + Bootstrap 5.3
RTL bundled by Vite (no CDN — the offline pages will cache it). IBM Plex Sans Arabic; brand
tokens `--aq-navy` / `--aq-maroon` in `resources/css/app.css`. Arabic UI through `__()` with
English keys in `lang/ar.json`; logical CSS properties only (`ms-`/`me-`, `inline-size`).

### Kernel and engines (spec §3, §4 — from Sub-project 2)

Everything reads from **four registries**: `parties` (one record per party, roles are rows),
`stock_movements` (signed, append-only), `ledger_entries` (additive; reversal = new row) and
`documents` (immutable once approved). **Nine shared engines** serve every module: rate master,
FX, dual calendar, notifications, discrepancy, approvals, barcode, offline sync, audit log.
Build an engine once — never per module. Layout: `app/Core/{Parties,Catalogue,Stock,Ledger,Documents}`,
`app/Engines/*`, `app/Modules/*` (screens and workflow only; a module owns no balance, price
or status). Invariants I1–I11 (spec §1) are failing tests in `tests/invariants/` before any
module, and live in PostgreSQL.

### Screens, roles and permissions

- `config/screens.php` is the ONE screen registry. Each screen has a key, Arabic label,
  route, sidebar group, icon and its levels. It drives the sidebar AND the permissions.
- Permissions are `{screen}.{level}`, levels cumulative: `view` ⊂ `edit` ⊂ `approve`
  (`App\Security\Screens::grantsFor`). Screens marked `signed_in` need sign-in only.
- Ten global Spatie roles (`App\Security\Roles`, spec §10.2). A user may hold several: the
  union applies. **There is no `Gate::before` and no bypass role.** `system_admin` is the
  vendor's support staff: users, roles, settings, audit log — never business data or approvals.
- `RolesAndPermissionsSeeder` runs on every deploy: it creates what is missing and grants a
  role its defaults only when it creates the role. It never revokes an editor change.
- `RoleManagementGuard` holds the assignment rules: nobody edits their own roles or a role
  they hold; only a manager grants manager, only a system_admin grants system_admin; an actor
  must hold every permission a role carries; the last active manager and the last active
  user who can edit users cannot be removed.
- **OPEN — decide before Sub-project 5 gives the business roles their screens.** Because an
  actor must hold every permission a role carries, `system_admin` (which holds no business
  screen) will be unable to assign a business role, or to edit, deactivate or reset the
  password of anyone who holds one. That is what stops the vendor's support account from
  minting an account with business-data access (spec §10.2: "no business data"), but spec
  §10.4 also gives it "Manage users and roles". Until the client decides, only a manager
  administers business staff.
- Routes: `$pres($uri, $controller, $screen)` in `routes/web.php` expands to the resource
  routes with `can:{screen}.view|edit`. Each later sub-project adds its own screens.

### Platform services (Sub-project 1)

| Service | Where | Notes |
|---|---|---|
| Audit | `App\Support\Audit\AuditTrail::record()` | Called EXPLICITLY inside each writer's transaction (fail-closed). Sub-project 2 replaces it with the trigger audit (spec §4.9). |
| Settings | `App\Models\Setting::current()` | One row (`CHECK id = 1`); a read never writes. |
| Attachments | `HasAttachments`, `AttachmentService`, `config/attachments.php` | Private disk; access = the parent's screen (`attachmentModule()`). |
| Notifications | `App\Services\Notifications\NotificationService::send()` | In-app only for now. |
| AI | `OpenRouterClient` (resolves to `AnthropicClient` when `ANTHROPIC_API_KEY` is set), `AiFeature::enabled()` | Drafts only. Features register in `config/ai.php`; none ship yet. |
| WhatsApp | `App\Services\WhatsApp\{WhatsAppClient,PhoneNumber}` | Env-only credentials; numbers default to Egypt (`20`), Arabic-Indic digits accepted. |
| Import/export | `App\Support\IO\*`, `config/io.php` `resources` | Two gates: `import_export.*` and the resource's own screen. The generic upsert is for tables WITHOUT a writer; a writer-owned table supplies `customImporter()`. |
| Backup | `backup:run` (02:15 daily), `config/backup.php` | Off-box only in production (S3-compatible and/or Google Drive). |
| Sign-in | `LoginController`, Google SSO behind `SSO_GOOGLE_ENABLED` (off) | Passwords always work while `SSO_PASSWORDS_RETIRED=false`. |

Guard tests in `tests/Feature/Guards/` fail on a `company_id` column, a float column, a stored
`balance`, a reference to removed ERP code, or a screen whose route does not exist.
`tests/Unit/NoProcessLevelMemoTest` fails on a function-level `static` in `app/`.

## Sub-projects

| # | Sub-project | Status |
|---|---|---|
| 1 | Copy & cut — platform layer, Arabic shell, roles, CI | done |
| 2 | Invariants & kernel (I1–I11, four registries, audit trigger) — **human review gate** | next |
| 3 | Shared engines (rate master, FX, discrepancy, notifications, calendar, barcode, offline) | |
| 4 | Packing-list demo — assisted correction | |
| 5 | Warehouses → Purchasing & Import | |
| 6 | Sales → Finance → Collections | |
| 7 | Costing → BOM → Job Orders — **opens with the two client questions** | |
| 8 | Freight & Containers | |
| 9 | Exhibitions, Reporting, Daily tracking | |
| 10 | portalScope + WhatsApp agent + in-system assistant | |
| 11 | Go-live (migration, stock count import, ETA e-invoicing) | |

Milestone 1 = Sub-projects 1–4. Each sub-project gets its own spec → plan → build cycle.

