# Alquds — Foundation design (Milestone 1, Sub-project 1 spec)

> Approved 15 Oct 2026 with the amendments below. Implementation plan: `docs/superpowers/plans/2026-10-15-alquds-copy-and-cut.md`.
>
> **User amendments (15 Oct 2026), applied below:**
> 1. The client spec copy is reconciled (§2, §13, §10.2, §10.4, §18) — Task 1.
> 2. A standing rule against Eloquent's mutable idioms goes in `CLAUDE.md` from commit 1 — A3, Task 1.
> 3. Sub-project 2 gets a line-by-line human review gate before Sub-project 3 — A2.
> 4. Sub-project 4 is assisted correction, not extraction — A2.
> 5. Sub-project 7 opens by flagging two missing client answers; the engine fails loudly — A2.

## A1. Context

INSHARO is building an ERP for Al-Quds, a Cairo publishing, printing, book-export and freight-consolidation company.
`Alquds_Project.md` (the client build spec, reconciled 15 Oct 2026, 1,728 lines) is the authority for **what** to build.
Rather than build from scratch, the new product starts from the INSHARO ERP (`INSHARODEV/erp`, Laravel 12).

A survey of that ERP found:
- **~70% of it is modules Al-Quds never uses**: HR, ATS, Learning, Village, Foundry, CRM and Engine OS, Meet, Convo, Tracking and so on.
- **Its operations and finance core contradicts the spec's invariants**: stored balances, editable invoices and payments, no FX history, no custody, integer quantities, double-entry instead of a party ledger.
- **What carries over is the platform layer.**

Decisions taken with the user (15 Oct 2026):

| # | Decision |
|---|---|
| D1 | New private repo `INSHARODEV/Alquds`, **fresh git history**. |
| D2 | **Al-Quds only.** No multi-company layer. |
| D3 | **Copy, then cut.** Harvest the platform layer from the ERP; build all business logic to the spec. The spec's §2 stack (NestJS/React) is replaced by Laravel 12 + PostgreSQL, a deliberate ENGINEERING change permitted by §2. |
| D4 | **Commit 1 holds kept files only.** None of INSHARO's internal notes, staff names or lead data enter the history. The source ERP commit is named in the message. |
| D5 | **Milestone 1 = Sub-projects 1–4:** foundation plus an early packing-list demo. |
| D6 | **No Super Admin bypass.** A tenth role, `system_admin`, for INSHARO support: users, roles, settings, audit log and backups only, with no business data and no approvals. |
| D7 | **Bootstrap 5.3 RTL instead of Tailwind** (ENGINEERING). It has logical `ms-`/`me-` utilities, and RTL is proven in the ERP's client portal. |
| D8 | **mPDF for documents.** DomPDF cannot shape Arabic. |
| D9 | **The database-trigger audit (invariant I4) lands in Sub-project 2** with the other invariants, not Sub-project 3. |

## A2. Decomposition

Each sub-project gets its own spec → plan → build cycle. This document fully specifies Sub-project 1 only.

| # | Sub-project | Delivers |
|---|---|---|
| **1** | **Copy & cut** | Alquds boots in Arabic RTL with sign-in, 10 roles, audit-log viewer, attachments and the notification centre. Tests pass on PostgreSQL in CI. Render files ready. No business features. |
| 2 | Invariants & kernel | I1–I11 as failing tests first. Then parties, catalogue (titles/editions), locations, stock_movements, ledger + cash, documents, approval_requests, and the audit trigger, all DB-enforced. **Gate:** a line-by-line human review of Sub-project 2, on its own, before Sub-project 3 starts. Never batched into an end-of-branch review — a ledger bug found in Sub-project 8 is expensive. |
| 3 | Shared engines | Rate master, FX, discrepancy, notifications (event→template→channel, snooze), dual calendar, barcode, offline sync |
| 4 | Packing-list demo | **Assisted correction, not extraction.** Capture (photo/scan/Excel) → AI draft lines with confidence → a mandatory review screen, always pre-filled, with no blank-form entry path → immutable customer packing list. `edited_by_user` is tracked per line from the first version. Demoed against the real handwritten list, not a clean one. |
| 5 | Warehouses → Purchasing & Import | Transfers, counts, 4 stock states, ATP, POs, three-way match, landed cost |
| 6 | Sales → Finance → Collections | Quote → proforma → final, credit check, aging, promise-to-pay, auto-block |
| 7 | Costing → BOM → Job Orders | Signature/ream cost engine with golden tests A and B, author rights. **Opens by flagging two missing client answers:** which printers bill per signature vs per ream (`print_pricing_method`), and whether `pages_per_signature` is always 16. A missing rate-master key fails loudly; a price, margin or waste % is never silently defaulted. |
| 8 | Freight & Containers | Consolidation, door scan, dual-track documents, agent financial lock, full customs packing list |
| 9 | Exhibitions, Reporting, Daily tracking | — |
| 10 | portalScope + WhatsApp agent + in-system assistant | — |
| 11 | Go-live | 1-year migration, 4-day stock count import, ETA e-invoicing |

## A3. Architecture rules (all sub-projects)

**Standing rule** (in `CLAUDE.md` from commit 1). The design is append-only tables and computed-never-stored balances, and Eloquent's idioms push the other way. So:
- One writer per table.
- No model events: `withEvents(discover: false)` stays, and listeners are registered by hand.
- No soft deletes on ledger or stock tables.
- No `save()` on an approved document.
- Invariants live in PostgreSQL triggers and CHECKs, never only in PHP.

**Layout**
- `app/Core/{Parties,Catalogue,Stock,Ledger,Documents}` — the registries.
- `app/Engines/*` — the nine engines.
- `app/Modules/*` — screens and workflow only. Modules own no balance, price or status.
- **One writer per table** (`StockService::move()`, `LedgerService::post()`, `DocumentService::approve()`).

**Money and quantities**
- Stored as `numeric(18,4)`; FX as `numeric(18,6)`.
- In PHP they are `Brick\Math\BigDecimal` via a custom Eloquent cast. Never float.
- A schema test fails on any other column type.

**Invariants live in PostgreSQL**
- Raw-SQL migrations add the triggers and CHECKs.
- Enums are text + CHECK (spec §2).
- Requests and jobs stamp `set_config('app.user_id', …)`, reset per request because persistent connections are on.

**Cost visibility (I10)**
- Non-managers read cost-bearing data through PostgreSQL views without price, cost or profit columns.
- Tests assert those columns are absent from every non-manager response.

**Documents**: mPDF, templates built from the supplied filled samples, three letterhead identities chosen by document type (§11.3).

**Offline (Sub-project 3)**
- PWA pages for the scanner and POS, with an IndexedDB write queue.
- Client UUID `idempotency_key`; the server dedupes.
- Queue depth always visible.

**AI**: the existing OpenRouter and Anthropic clients produce **drafts only**. Token and message use is logged per party per month from day one (§7.16 pass-through billing).

**Hosting**: Render (web + worker + scheduler in one container), Neon PostgreSQL 15+, R2 private disk, nightly backups. The user provisions the accounts.

## A4. Sub-project 1 — Copy & cut

### Keep-list (harvested from `INSHARODEV/erp`, then adapted)

**Laravel skeleton and infrastructure**
- `artisan`, `bootstrap/`, `public/index.php`
- core `config/*` (app, auth, cache, database, filesystems, logging, mail, queue, services, session, permission, backup)
- Vite config, Docker (`Dockerfile`, `docker/*`), `render.yaml`
- `LazyDatabaseSessionHandler`, the Neon `pgsql` options
- `RunBackup` + `config/backup.php`

**Auth**
- `Auth/LoginController` and the password flow.
- `GoogleSsoController`, `Services/Auth/{GoogleSsoClient,SsoSignIn}` behind a switch (off by default).
- `EnsureAccountCanSignIn`.

**Users & RBAC**
- `User` (slimmed), Spatie permission, `RoleManagementGuard`, user management.

**Platform services**
- **Audit:** `Models/Concerns/Auditable`, `AuditLog`, `AuditLogController` + view.
- **Attachments:** `HasAttachments`, `AttachmentService`, `Attachment`, `AttachmentController`, `AttachmentPolicy`, `config/attachments.php` (registry emptied).
- **Notifications:** `NotificationService` (in-app channel), `ErpNotification`, `NotificationController`, the bell.
- **AI:** `Services/Ai/*` (OpenRouter, Anthropic, provider binding); `config/ai.php` pruned to tiers plus Al-Quds features.
- **WhatsApp:** `Services/Client/WhatsAppClient` → moved to `App\Services\WhatsApp\WhatsAppClient`, env credentials only.
- **Import/export:** `Support/IO` core, without `Schemas/` and `Importers/`.

### Left behind
- Every business module, including the ERP's sales, purchasing, inventory, accounting, banking, expenses, assets and catalog.
- Multi-company and group mode.
- The client portal, Workflow, Studio, DomPDF.
- `village/`, `desktop-agent/`.
- All 348 migrations, `CLAUDE.md`, `docs/`.
- Anything can be re-harvested later from the ERP repo, which stays intact.

### Platform rework

**No company layer**
- `CompanyScope`, `BelongsToCompany`, `CompanyContext`, `SetActiveCompany` and `company_user` are not harvested.
- Kept models lose `company_id`.
- Company identity becomes a settings record.

**RBAC**
- Spatie global roles. A user may hold several roles; permissions are the union.
- Ten roles: `manager, accountant, sales_officer, collections_officer, shipping_officer, storekeeper, purchasing_officer, production_officer, exhibitions_officer, system_admin`.
- `config/screens.php` is the one screen registry. Each screen has a key, an Arabic label, a route, a sidebar group, an icon and its levels. It drives the sidebar **and** permissions `{screen}.view|edit|approve`.
- Levels are cumulative: edit ⊃ view, approve ⊃ edit. "None" = no permission held.
- `home` (and the personal `notifications` / `profile` pages) need sign-in only, so a user with no roles still lands somewhere.
- The role editor is a screens × {none, view, edit, approve} matrix.
- No `Gate::before` bypass.
- **SP1 screens:** `home`, `users`, `roles`, `settings`, `audit_log`, `import_export`. Each later sub-project adds its own screens.
- **Default grants:**
  - `manager` gets everything.
  - `system_admin` gets `users`, `roles`, `settings` (edit) and `audit_log` (view).
  - The other eight roles get nothing yet; their business screens arrive with their sub-projects.
- **Assignment rules (ENGINEERING):**
  - Nobody changes their own roles, or the matrix of a role they hold.
  - Only a `manager` may grant `manager`; only a `system_admin` may grant `system_admin`.
  - The last active manager and the last active user able to edit users can never be removed.
- **Seeder re-runs** (`entrypoint.sh` seeds on every boot) create missing roles and permissions but never revoke a grant changed in the editor.

**Migrations**: a fresh baseline only — users, password resets, sessions, cache, jobs/failed_jobs, Spatie permission tables, audit_logs, attachments, erp_notifications, settings, import runs.

**Arabic shell**
- `layouts/app` rebuilt RTL (`<html dir="rtl" lang="ar">`) on Bootstrap 5.3 RTL, bundled via Vite (no CDN — the offline pages cache it later).
- IBM Plex Sans Arabic; navy (كحلي) / maroon (نبيتي) tokens; a logo slot.
- Sidebar from `config/screens.php`. `app.locale = ar`; strings in `lang/ar`.
- Empty home page.

**Tests and CI**
- `phpunit.xml` uses PostgreSQL (`alquds_test`).
- `.github/workflows/ci.yml` runs a Postgres 16 service, `pint --test` and `php artisan test`.
- Kept platform tests are ported. New tests cover the role matrix, multi-role union, no bypass, a schema check (no `company_id`, no float money), and a reference check (no removed namespace anywhere).

**Docs**
- Client spec → `docs/spec/Alquds_Project.md`.
- New `CLAUDE.md` describing Alquds only.
- This spec + plan under `docs/superpowers/`.

**PHP version**
- Production stays PHP 8.4 (Docker).
- `composer.json` requires `^8.3` (the pruned set resolves on it), so the suite also runs in this environment (PHP 8.3.6).

**Settings**: a single-row `settings` table replaces `company_settings`, `google_settings` and `meta_settings`. Columns: company name ar/en, logo, timezone, locale. Credentials stay env-only.

**Google sign-in**: off by default (`SSO_GOOGLE_ENABLED=false`). `passwords_retired` defaults to false, so passwords always work until Al-Quds has a Workspace domain.

### Done when

1. A fresh clone installs, migrates and seeds.
2. A seeded manager signs in to an Arabic RTL navy/maroon shell.
3. Each role sees exactly its screens; a two-role user sees the union; `system_admin` sees only its four screens and no business data.
4. CI is green on PostgreSQL, Pint is clean, and a grep for removed namespaces finds nothing.

### Open (do not guess; does not block Sub-project 1)
- Whether Al-Quds has a Google Workspace domain. Until it does, SSO stays off and sign-in is password-only.
- The logo files and exact brand hex values. Placeholder tokens are used until supplied.
- Render and Neon accounts for deploy.
