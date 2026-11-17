# CLAUDE.md

Guidance for Claude Code (claude.ai/code) working in this repository.

## What this is

**MASDAR's ERP.** It started as a copy of the starter ERP
(`yosefelwardany1-del/Empty-ERP` at `88da6fd`): the platform layer only (sign-in, roles and
screen permissions, audit log, settings, attachments, notifications, AI and WhatsApp clients,
import/export, backups) behind an Arabic RTL shell, with no business module yet. MASDAR's
modules are built on it to MASDAR's specification.

**The authority for this build is MASDAR's spec, `docs/spec/PROJECT.md`** (still the template
until MASDAR's spec replaces it). Where anything here, in a plan, or in the code disagrees with the
spec, the spec wins. Items it marks `OPEN` are built configurable and fail loudly — never
guessed, never silently defaulted.

**When anything stops you, go back to the spec first**: settle the question from it and record
the reading; ask the user only when the spec is silent or contradicts itself on a choice that
is theirs.

Designs and build plans live in `docs/superpowers/specs/` and `docs/superpowers/plans/`.

## Starting a client

Where MASDAR stands on each step is marked; every `OPEN` step waits on MASDAR's spec.

1. Put the client's build spec in `docs/spec/PROJECT.md` (keep the template's headings).
   **OPEN** — the file names MASDAR but the spec itself is not written yet.
2. Name it: `APP_NAME` (env), the company name, Arabic name and logo (Settings screen), the
   brand colours `--app-primary` / `--app-accent` in `resources/css/app.css`.
   **Done:** `APP_NAME=MASDAR` (`.env.example`, `render.yaml`). **OPEN:** the Arabic name,
   the logo and the brand colours (the starter's colours stay until then); the company name
   and Arabic name are entered on the Settings screen after the first deploy.
3. Set the regional defaults if they differ: `APP_TIMEZONE` (default `Africa/Cairo`, also the
   time the scheduler runs in), `APP_LOCALE` (`ar`), `WHATSAPP_DEFAULT_COUNTRY_CODE` (`20`).
   **OPEN** — the starter's Egyptian defaults stay until the spec confirms MASDAR's.
4. Replace the starter roles in `app/Security/Roles.php` with the spec's, and give each its
   screens in `Roles::defaultGrants` as the screens are built. **OPEN.**
5. Split the spec into sub-projects; each gets its own spec → plan → build cycle, and this
   file gains a section per sub-project as it lands. **OPEN.**

Starter fixes are pulled in from the `Empty-ERP` repository (add it as a remote named
`starter` and merge its `main`); MASDAR's own work never goes back to it.

## Commands

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoUsersSeeder   # local only: {role}@example.test / password
php artisan serve                              # http://localhost:8000
php artisan queue:work                         # large imports run here
php artisan test            # PostgreSQL only (see phpunit.xml) — never SQLite
php artisan test --filter=RoleMatrixTest
./vendor/bin/pint           # before every commit
php artisan user:create someone@example.com "Name" --role=manager --generate
```

Tests need a local PostgreSQL 16 with role `erp` / password `erp` and a database `erp_test`
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

Laravel 12, PHP 8.3+ (8.4 in Docker), PostgreSQL 16 (Neon in production), Blade + Bootstrap 5.3
RTL bundled by Vite (no CDN). IBM Plex Sans Arabic; brand tokens `--app-primary` /
`--app-accent` in `resources/css/app.css`, classes prefixed `app-`. Arabic UI through `__()`
with English keys in `lang/ar.json`; logical CSS properties only (`ms-`/`me-`, `inline-size`).
Single company: there is no multi-company layer (a guard test fails on a `company_id` column).

### Screens, roles and permissions

- `config/screens.php` is the ONE screen registry. Each screen has a key, Arabic label,
  route, sidebar group, icon and its levels. It drives the sidebar AND the permissions.
- Permissions are `{screen}.{level}`, levels cumulative: `view` ⊂ `edit` ⊂ `approve`
  (`App\Security\Screens::grantsFor`). Screens marked `signed_in` need sign-in only.
- Starter roles (`App\Security\Roles`): `manager` (every screen), `supervisor` and `staff`
  (nothing until a client's screens exist), `system_admin`. A user may hold several: the
  union applies. **There is no `Gate::before` and no bypass role.** `system_admin` is the
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
| Attachments | `HasAttachments`, `AttachmentService`, `config/attachments.php` | Private disk; access = the parent's screen (`attachmentModule()`). |
| Notifications | `App\Services\Notifications\NotificationService::send()` | In-app only for now. |
| AI | `OpenRouterClient` (resolves to `AnthropicClient` when `ANTHROPIC_API_KEY` is set), `AiFeature::enabled()` | Drafts only. Features register in `config/ai.php`; none ship yet. |
| WhatsApp | `App\Services\WhatsApp\{WhatsAppClient,PhoneNumber}` | Env-only credentials; numbers without a country code take `WHATSAPP_DEFAULT_COUNTRY_CODE`, Arabic-Indic digits accepted. |
| Import/export | `App\Support\IO\*`, `config/io.php` `resources` | Two gates: `import_export.*` and the resource's own screen. The generic upsert is for tables WITHOUT a writer; a writer-owned table supplies `customImporter()`. |
| Backup | `backup:run` (02:15 daily), `config/backup.php` | Off-box only in production (S3-compatible and/or Google Drive). |
| Sign-in | `LoginController`, Google SSO behind `SSO_GOOGLE_ENABLED` (off) | Passwords always work while `SSO_PASSWORDS_RETIRED=false`. |

Guard tests in `tests/Feature/Guards/` fail on a `company_id` column, a float column, a stored
`balance`, a reference to removed code, a former client's name, or a screen whose route does
not exist. `tests/Unit/NoProcessLevelMemoTest` fails on a function-level `static` in `app/`.
