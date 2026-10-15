# Alquds Copy & Cut Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new private repo `INSHARODEV/Alquds` that boots as a single-company, Arabic RTL Laravel 12 app with sign-in, 10 roles, a screen-permission matrix, audit log, attachments, notifications, AI/WhatsApp clients and import/export. Tests run on PostgreSQL, and nothing of the ERP's business modules or INSHARO internals is carried over.

**Architecture:**
- The platform layer is harvested file by file from `/home/user/erp` (source commit `eaed57d`) into an empty working tree, then adapted: no company layer, no Super Admin bypass, screens-driven RBAC, Arabic shell.
- Each task ends in one commit to `main` of the new repo. The repo is new and empty and the user asked for it, so no other work shares it.

**Tech Stack:** Laravel 12, PHP ^8.3 (Docker 8.4), PostgreSQL 16, spatie/laravel-permission 8, Bootstrap 5.3 RTL + Bootstrap Icons + IBM Plex Sans Arabic via Vite, PHPUnit 11, Pint.

**Spec:** `docs/superpowers/specs/2026-10-15-alquds-foundation-design.md`; client spec `docs/spec/Alquds_Project.md`.

**Harvest manifest:** before Task 1, save the harvest manifest (the line-level keep/edit list for every harvested file, produced during planning) to the session scratchpad as `harvest-manifest.md`. It stays out of the repo because it describes ERP internals. Every "harvest" step below means: copy from `/home/user/erp/<same path>` and apply that file's manifest edits.

## Global Constraints

- `src` = `/home/user/erp` (read-only); `dst` = `/home/user/Alquds` (the new clone).
- Codebase English; UI Arabic (`app.locale = ar`, `<html lang="ar" dir="rtl">`). Use logical spacing classes (`ms-`/`me-`/`ps-`/`pe-`), never `ml-`/`mr-` or physical `left`/`right` in new CSS.
- No `company_id` column, no `CompanyScope`/`BelongsToCompany`/`CompanyContext`, no `Gate::before`, anywhere.
- Permission names are `{screen}.{level}`, level ∈ `view|edit|approve`. Verb mapping in harvested code: view/export → `view`; create/edit/delete/import → `edit`.
- Enum-like columns: `string` + PostgreSQL CHECK (spec §2). No float or double columns.
- Tests run on PostgreSQL only (`DB_CONNECTION=pgsql`, database `alquds_test`). No SQLite.
- No user-facing "INSHARO" text in `app/`, `config/`, `resources/`, `lang/`, `routes/`.
- `withEvents(discover: false)` stays on. Any future listener is registered by hand in `AppServiceProvider`.
- Run `./vendor/bin/pint` before every commit.

## Review Focus

1. **A user holding two roles where one grants `view` and the other `edit` on the same screen** must get `edit` (union), never the lower. Test in Task 2.
2. **A signed-in user with no roles** lands on `home` (200) with an empty business menu, never a 403 loop. Test in Task 3.
3. **Removing the last active manager, or the last active user who can edit users,** is refused with a clear Arabic message (lock-out). Tests in Task 6.
4. **Re-running `RolesAndPermissionsSeeder` on boot** keeps matrix edits made in the editor. Test in Task 2.
5. **Arabic-Indic digits (٠١٢٣٤٥٦٧٨٩) typed into a phone number** normalise to Western digits with Egypt's `20` code. Test in Task 8.

---

### Task 1: New repo, skeleton and PostgreSQL test harness (commit 1)

**Files:**
- Create repo: `INSHARODEV/Alquds` (private, empty) with `mcp__github__create_repository`, then `add_repo` (access `push`), clone to `dst`.
- Harvest (manifest A1/A2):
  - `artisan`, `bootstrap/app.php` (manifest C: web routes + console + `/up`, `ProfileRequest`, `trustProxies`, `EnsureAccountCanSignIn` added in Task 5), `bootstrap/providers.php` (only `AppServiceProvider`)
  - `public/{index.php,.htaccess,robots.txt,favicon.ico}`, `.gitignore`, `.gitattributes`, `.editorconfig`, `.dockerignore`, the `storage/**/.gitignore` files
  - `config/{app,auth,cache,database,filesystems,logging,mail,queue,services,session,permission}.php`
  - `Dockerfile`, `docker/*`, `render.yaml` (one `alquds` service, `healthCheckPath: /up`)
  - `app/Http/Controllers/Controller.php`, `app/Http/Middleware/ProfileRequest.php`
  - `app/Support/Session/LazyDatabaseSessionHandler.php`
  - `app/Providers/AppServiceProvider.php` (only the session-handler, `Paginator::useBootstrapFive`, `URL::forceScheme` lines for now)
- Create:
  - `composer.json` (manifest G: php `^8.3`; keep laravel/framework, tinker, anthropic-ai/sdk, spatie/laravel-permission, league/flysystem-aws-s3-v3; add phpoffice/phpspreadsheet ^1.30; dev as-is) and a fresh `composer.lock`
  - `package.json` (vite, laravel-vite-plugin, bootstrap@5.3.x, bootstrap-icons, @fontsource/ibm-plex-sans-arabic)
  - `vite.config.js`
  - `phpunit.xml` (pgsql env: `DB_HOST=127.0.0.1 DB_DATABASE=alquds_test DB_USERNAME=alquds DB_PASSWORD=alquds DB_PERSISTENT=false`, `CACHE_LOCAL_DRIVER=array`, array session/mail, sync queue)
  - `.env.example` (Alquds values only)
  - `routes/web.php` (empty `auth` group placeholder), `routes/console.php`
  - `database/migrations/0001_01_01_000000_create_users_table.php` (users with final columns from manifest B, `users_status_check`; `password_reset_tokens`; `sessions`), `0001_01_01_000001_create_cache_table.php`, `0001_01_01_000002_create_jobs_table.php`
  - `database/factories/UserFactory.php` (harvest)
  - `tests/TestCase.php` (calls `$this->withoutVite()` in `setUp`)
  - `docs/spec/Alquds_Project.md`: a copy of the uploaded client spec, **reconciled** (amendment 1):
    - **§2 Stack:** NestJS/React/Tailwind → Laravel 12 + PostgreSQL 16 + Bootstrap 5.3 RTL, marked `ENGINEERING`. Reason: harvested from the INSHARO ERP platform layer, where RTL is proven in production.
    - **§13:** Bootstrap 5.3 RTL with logical `ms-`/`me-` utilities, not Tailwind.
    - **§10.2:** nine roles → ten. Add `system_admin` — INSHARO support only: users, roles, settings, audit log, backups. No business data, no approvals, no `Gate::before` bypass.
    - **§10.4:** add the `system_admin` column (✅ only on the admin capabilities; ❌ on every business capability, including "View prices / cost / profit" and "Approve any of the 13").
    - **§18:** a `15 Oct` row recording these four changes.
  - `CLAUDE.md` (first version): what Alquds is, a pointer to `docs/spec/Alquds_Project.md` as the authority, the **standing rule** (A3), the Sub-project 2 review gate, the Sub-project 4 framing and the Sub-project 7 flags (A2). Task 9 completes it.
  - `docs/superpowers/specs/2026-10-15-alquds-foundation-design.md` (the spec)
  - `docs/superpowers/plans/2026-10-15-alquds-copy-and-cut.md` (this plan)
- Test: `tests/Feature/Platform/BootTest.php`

**Interfaces:**
- Produces: `users` table (id, name, email unique, phone, phone_e164, avatar, avatar_disk, status ∈ active|inactive|pending, last_login, approved_at, approved_by, email_verified_at, password, remember_token, timestamps); `Tests\TestCase`.

- [ ] **Step 1: Start PostgreSQL and create the test database**

Run: `pg_ctlcluster 16 main start && su postgres -c "psql -c \"CREATE ROLE alquds LOGIN PASSWORD 'alquds' CREATEDB\"" && su postgres -c "createdb -O alquds alquds_test"`
Expected: no error; `psql -h 127.0.0.1 -U alquds alquds_test -c 'select 1'` prints 1.

- [ ] **Step 2: Write the failing test** `BootTest`
  - `test_suite_runs_on_postgresql`: `assertSame('pgsql', DB::connection()->getDriverName())`
  - `test_health_endpoint_is_up`: `get('/up')->assertOk()`
  - `test_users_status_is_checked_by_the_database`: inserting a user with `status = 'banned'` throws `QueryException`

- [ ] **Step 3: Harvest and create the files above, then `composer install && npm install && npm run build`**

- [ ] **Step 4: Run** `php artisan test --filter=BootTest`. Expected: 3 passed. Also confirm that `grep -n "system_admin" docs/spec/Alquds_Project.md` hits §10.2, §10.4 and §18, and that `grep -n "NestJS\|Tailwind" docs/spec/Alquds_Project.md` hits only the §18 change-log row.

- [ ] **Step 5: Commit 1 and push**

```bash
git add -A && git commit -m "Start Alquds from the INSHARO ERP platform layer (source erp@eaed57d)" && git push -u origin main
```

---

### Task 2: Screens registry, ten roles, no bypass

**Files:**
- Harvest: `database/migrations/2026_06_06_145419_create_permission_tables.php` (as-is)
- Create:
  - `config/screens.php`
  - `app/Security/Roles.php`
  - `app/Security/Screens.php`
  - `database/seeders/RolesAndPermissionsSeeder.php`
  - `database/seeders/DatabaseSeeder.php`
  - `routes/web.php` gains the `$pres` helper (manifest D, verbs mapped to levels)
- Modify: `app/Models/User.php` (harvest + manifest A4 slimming; keeps `HasRoles`, `canSignIn()`, `isPending()`, `avatarUrl()`, `approvedBy()`)
- Test: `tests/Feature/Security/RoleMatrixTest.php`

**Interfaces:**
- Produces:
  - `config('screens')`: `[key => ['label' => string(ar), 'route' => string, 'group' => string, 'icon' => string, 'levels' => list<'view'|'edit'|'approve'>, 'signed_in' => bool]]`. SP1 keys: `home` (signed_in), `users`, `roles`, `settings` (view, edit); `audit_log` (view); `import_export` (view, edit).
  - `App\Security\Roles`: constants `MANAGER … SYSTEM_ADMIN`; `Roles::ALL: list<string>`; `Roles::label(string $role): string` (Arabic, spec §10.2).
  - `App\Security\Screens`:
    - `permission(string $screen, string $level): string`
    - `grantsFor(string $screen, string $level): list<string>` — cumulative
    - `levelOf(User $u, string $screen): ?string`
    - `allows(User $u, string $screen, string $level): bool`
    - `visibleFor(User $u): list<array>` — sidebar items
    - `allPermissions(): list<string>`
  - `$pres(string $uri, string $controller, string $screen)`: index/show → `can:{screen}.view`; create/store/edit/update/destroy → `can:{screen}.edit`.
  - Seeder: idempotent. Creates missing permissions and roles. Grants defaults **only when it creates the role**.

- [ ] **Step 1: Write failing tests** in `RoleMatrixTest` (seeded with `RolesAndPermissionsSeeder`)
  - `test_ten_roles_exist`: `assertEqualsCanonicalizing(Roles::ALL, Role::pluck('name')->all())`, count 10
  - `test_manager_holds_every_permission`
  - `test_system_admin_holds_only_admin_screens`: has `users.edit`, `roles.edit`, `settings.edit`, `audit_log.view`; lacks `import_export.view`
  - `test_levels_are_cumulative`: `grantsFor('users','edit') === ['users.view','users.edit']`
  - `test_two_roles_union_takes_the_higher_level` (Review Focus 1): test role `a` granted `grantsFor('users','view')`, test role `b` granted `grantsFor('users','edit')`, user holds both → `levelOf($u,'users') === 'edit'` and `allows($u,'users','edit')` is true
  - `test_no_gate_bypass`: a user with a role named `Super Admin` (created ad hoc) is denied `users.view`
  - `test_reseeding_keeps_matrix_edits` (Review Focus 4): revoke `settings.edit` from `system_admin`, re-run the seeder → still revoked

- [ ] **Step 2: Run** `php artisan test --filter=RoleMatrixTest`. Expected: FAIL (classes missing).

- [ ] **Step 3: Implement** `config/screens.php`, `Roles`, `Screens`, the seeder, `DatabaseSeeder`, the slim `User`. `levelOf` returns the highest level whose permission the user holds, via `$u->hasPermissionTo()`.

- [ ] **Step 4: Run** the same command. Expected: all pass.

- [ ] **Step 5: Commit** `git commit -m "Add the screen registry, ten roles and cumulative screen permissions"`

---

### Task 3: Arabic RTL shell, sidebar and home

**Files:**
- Create:
  - `resources/views/layouts/app.blade.php` (rebuilt from manifest E: head with `lang="ar" dir="rtl"`, `@vite`, `partials._favicon`; brand from `Setting` added in Task 7, placeholder text until then; sidebar from `Screens::visibleFor(auth()->user())` grouped by `group`; user menu; flash; `@yield('content')`)
  - `resources/css/app.scss` (imports `bootstrap/dist/css/bootstrap.rtl.min.css`, bootstrap-icons, IBM Plex Sans Arabic; `:root` tokens `--aq-navy`, `--aq-maroon` with placeholder values)
  - `resources/js/app.js`
  - `app/Http/Controllers/DashboardController.php` (`__invoke`, empty home)
  - `resources/views/dashboard.blade.php`
  - `lang/ar.json`
- Harvest:
  - `partials/_workspace_styles.blade.php`, with every physical left/right listed in manifest E converted to logical properties
  - `partials/_workspace_scripts.blade.php` (minus chart/palette/nash)
  - `partials/_favicon.blade.php`, `partials/delete-modal.blade.php`, `components/hr/avatar.blade.php`
- Modify: `routes/web.php` (`GET /` → `dashboard`, auth only); `config/app.php` (`locale` = `ar`, `fallback_locale` = `en`)
- Test: `tests/Feature/Platform/ShellTest.php`

**Interfaces:**
- Consumes: `Screens::visibleFor()`.
- Produces: `layouts.app` sections `title`, `content`; route `dashboard`; `lang/ar.json` (English source string → Arabic). Every later view uses `__()` with English keys.

- [ ] **Step 1: Write failing tests** in `ShellTest`
  - `test_home_renders_rtl_arabic`: manager `get('/')` → 200, `assertSee('dir="rtl"', false)`, `assertSee('lang="ar"', false)`
  - `test_manager_sidebar_lists_every_screen`: sees the Arabic labels of `users`, `roles`, `settings`, `audit_log`, `import_export`
  - `test_system_admin_sidebar_has_only_its_screens`: sees users/roles/settings/audit_log labels; not import_export
  - `test_user_without_roles_lands_on_home` (Review Focus 2): 200, no admin labels
  - `test_guest_is_redirected_to_login`: `get('/')->assertRedirect('/login')`

- [ ] **Step 2: Run** `php artisan test --filter=ShellTest`. Expected: FAIL.

- [ ] **Step 3: Implement** the layout, styles, controller, view, `ar.json` and route. Run `npm run build`.

- [ ] **Step 4: Run** the same command. Expected: all pass.

- [ ] **Step 5: Commit** `git commit -m "Add the Arabic RTL shell, the screen-driven sidebar and an empty home"`

---

### Task 4: Notification centre

**Files:**
- Harvest (manifest A7):
  - `app/Services/Notifications/NotificationService.php` (no `CompanyContext`, no `company_id`)
  - `Channels/*` (`InAppNotificationChannel` without `company_id`)
  - `NotificationType.php` (only `USER_PENDING`, `USER_APPROVED`, `SYSTEM`)
  - `app/Models/ErpNotification.php`
  - `app/Http/Controllers/NotificationController.php`
  - `resources/views/notifications/index.blade.php`
  - `config/notifications.php`
- Create:
  - migration `2026_10_15_000001_create_erp_notifications_table.php` (manifest B columns, no `company_id`)
  - the bell in `layouts/app` (manifest E L427–457 minus `_communication_alerts`)
- Modify: `routes/web.php` (manifest D notifications routes, auth only)
- Test: harvest `tests/Feature/NotificationSystemTest.php` → `tests/Feature/Platform/NotificationCentreTest.php`; drop the company-isolation and expense cases.

**Interfaces:**
- Produces:
  - `NotificationService::send(User|int|iterable $recipients, string $title, string $message, array $opts = []): int` (`$opts`: `type`, `category`, `icon`, `url`)
  - `unreadCountFor(User|int|null $user): int`
  - `recentFor(User|int|null $user, int $limit = 8): Collection`

- [ ] **Step 1: Port the tests.** Keep: category stamped, recipients de-duplicated, a user sees only their own notifications, mark-all-read. Add `test_bell_shows_unread_count` (layout renders the count for the signed-in user).
- [ ] **Step 2: Run** `php artisan test --filter=NotificationCentreTest`. Expected: FAIL.
- [ ] **Step 3: Harvest and edit** per manifest A7.
- [ ] **Step 4: Run.** Expected: pass. Also run the full suite: `php artisan test`.
- [ ] **Step 5: Commit** `git commit -m "Add the in-app notification centre and bell"`

---

### Task 5: Sign-in (password, optional Google) and profile

**Files:**
- Harvest (manifest A3, A13):
  - `Auth/LoginController.php`, `Auth/Concerns/ResolvesSignInHome.php` (landing = `dashboard`)
  - `Auth/GoogleSsoController.php`, `Services/Auth/{GoogleSsoClient,SsoSignIn,SsoOutcome,SsoProfile}.php` (`approvers()` = active users holding `users.edit`)
  - `Models/UserSsoIdentity.php`, `UserSsoIdentityController.php`
  - `Middleware/EnsureAccountCanSignIn.php` (append to the web group)
  - `ProfileController.php`, `ProfilePhotoController.php`, `Services/Media/ImageStore.php`
  - `Console/Commands/SetUserPassword.php`
  - views `auth/{_portal,_google_g,login,pending}.blade.php` (Arabic RTL, Alquds brand, manifest E edits), `profile/index.blade.php`, `components/image-upload.blade.php`
  - `config/sso.php`: `enabled` default **false**, `passwords_retired` default **false**, empty allowed domains, empty break-glass, LinkedIn removed
- Create: migration `2026_10_15_000002_create_user_sso_identities_table.php` (manifest B)
- Modify: `routes/web.php` (manifest D: login, logout, Google, pending, profile routes; Google routes return 404 when disabled)
- Test (ported):
  - `tests/Feature/Auth/LoginAccountStatusTest.php`
  - `GoogleSsoSignInTest.php` — drop company setup and the `approving_without_a_company` case; approvers = `users.edit` holders
  - `SignInLandingTest.php` — drop Recruiter/HR cases
  - `SetUserPasswordCommandTest.php`
  - `ProfilePhotoTest.php` — photo cases only

**Interfaces:**
- Consumes: `NotificationService::send` (pending Google sign-ups notify approvers); `Screens`.
- Produces: routes `login`, `login.post`, `logout`, `sso.google`, `sso.google.callback`, `sso.pending`, `profile`, `profile.update`, `profile.password`, `profile.photo.*`.

- [ ] **Step 1: Port the tests and add:**
  - `test_google_button_hidden_and_routes_404_when_disabled`
  - `test_password_login_works_when_sso_enabled_but_no_domain_configured`
- [ ] **Step 2: Run** `php artisan test tests/Feature/Auth`. Expected: FAIL.
- [ ] **Step 3: Harvest and edit** per manifest A3/A13. All login copy goes through `__()` with Arabic in `lang/ar.json`.
- [ ] **Step 4: Run.** Expected: pass. Then run the full suite.
- [ ] **Step 5: Commit** `git commit -m "Add password and optional Google sign-in, account status checks and the profile page"`

---

### Task 6: Users and role management (matrix editor, assignment rules)

**Files:**
- Harvest (manifest A4):
  - `app/Security/RoleManagementGuard.php` — rewritten rules below
  - `UserController.php` — multi-role select instead of single `role`; no companies, StaffMerge, clients or impersonation
  - `RoleController.php` — matrix instead of a permission list; no scopes
  - views `users/{index,create,edit,show}.blade.php`, `roles/{index,create,show}.blade.php`
- Create:
  - `resources/views/roles/edit.blade.php` (the screens × none/view/edit/approve matrix, one radio row per screen, only the levels the screen declares)
  - `app/Console/Commands/CreateUser.php` (`user:create {email} {name} {--role=*}`)
  - `database/seeders/DemoUsersSeeder.php` (one user per role; `local`/`testing` only)
- Modify: `routes/web.php` (`$pres('users', UserController::class, 'users')`, `$pres('roles', RoleController::class, 'roles')`, `users.sso-identities.*`)
- Test:
  - `tests/Feature/Security/RoleManagementGuardTest.php` (ported L58–127 and L250–278 of `UserRoleManagementSecurityTest`, plus the new rules)
  - `tests/Feature/Admin/UserAdminTest.php` (ports `UserLastLoginCastTest`)
  - `tests/Feature/Admin/RoleMatrixEditorTest.php`

**Interfaces:**
- Consumes: `Screens::grantsFor`, `Roles`.
- Produces: `RoleManagementGuard::canAssignRole(User $actor, string $role, User $target): bool`; `canEditRole(User $actor, Role $role): bool`; `assertKeepsAdmins(User $target, array $newRoles): void` (throws `ValidationException`).

- [ ] **Step 1: Write failing tests**
  - `test_nobody_changes_their_own_roles`
  - `test_only_a_manager_grants_manager`
  - `test_only_a_system_admin_grants_system_admin`
  - `test_role_matrix_of_a_held_role_is_read_only`
  - `test_last_active_manager_cannot_lose_the_role` (Review Focus 3)
  - `test_last_user_who_can_edit_users_cannot_be_removed_or_deactivated` (Review Focus 3)
  - `test_matrix_save_grants_cumulative_permissions`: saving `users = edit` grants `users.view` + `users.edit`
  - `test_user_can_hold_several_roles`
  - `test_user_create_command_assigns_roles`
  - `test_users_index_shows_never_and_ago` (ported)
- [ ] **Step 2: Run** `php artisan test tests/Feature/Admin tests/Feature/Security`. Expected: FAIL.
- [ ] **Step 3: Implement.** Guard messages are Arabic via `__()`.
- [ ] **Step 4: Run.** Expected: pass. Then run the full suite.
- [ ] **Step 5: Commit** `git commit -m "Add user and role management with the screen matrix and lock-out guards"`

---

### Task 7: Audit log, settings and attachments

**Files:**
- Harvest:
  - `Models/Concerns/Auditable.php` (without the `company_id` line; manifest H1)
  - `Models/AuditLog.php`, `AuditLogController.php` (gate `audit_log.view`), `audit-logs/index.blade.php`
  - `Models/Concerns/HasAttachments.php`, `Models/Attachment.php`, `Services/Attachments/AttachmentService.php`, `Http/Controllers/AttachmentController.php`, `Policies/AttachmentPolicy.php` — all per manifest A5/A6, with permission checks becoming `{attachmentModule()}.view|edit`
  - `config/attachments.php` (`attachables => []`), `partials/attachments.blade.php`
  - `Support/AppTimezone.php` (reads `Setting`)
- Create:
  - migrations `2026_10_15_000003_create_audit_logs_table.php`, `…000004_create_attachments_table.php`, `…000005_create_settings_table.php` (manifest B; settings = company_name, company_name_ar, logo, logo_disk, timezone default `Africa/Cairo`, locale default `ar`, timestamps)
  - `app/Models/Setting.php` (`Setting::current(): Setting`, creates the row if missing; no static memo)
  - `SettingsController.php` + `settings/edit.blade.php` (name ar/en, timezone, logo upload via `ImageStore`)
  - add `Auditable` to `User` and `Setting`
- Modify: `routes/web.php` (`audit-logs.index`, attachments routes, `settings.edit` / `settings.update` under `settings.view|edit`); `layouts/app` brand reads `Setting::current()`
- Test:
  - ported `tests/Feature/Platform/AuditLogPageTest.php` and `AttachmentSystemTest.php` (fixture attachable: a test-only model + migration under `tests/Fixtures`, registered via `config(['attachments.attachables' => …])`)
  - ported `AppTimezoneTest.php`
  - new `SettingsTest.php`

**Interfaces:**
- Produces: `Setting::current()`; `HasAttachments` contract `attachmentModule(): string` (a screen key); the `Auditable` trait.

- [ ] **Step 1: Write and port the tests**
  - `test_changing_a_user_writes_an_audit_row_with_old_and_new_values`
  - `test_audit_log_requires_audit_log_view`
  - `test_settings_edit_requires_settings_edit`
  - `test_brand_shows_company_name_ar`
  - the attachment upload/download/delete, mime/size refusal and fail-closed cases (ported)
- [ ] **Step 2: Run** `php artisan test tests/Feature/Platform`. Expected: FAIL.
- [ ] **Step 3: Harvest, create and edit** as listed.
- [ ] **Step 4: Run.** Expected: pass. Then run the full suite.
- [ ] **Step 5: Commit** `git commit -m "Add the audit log, company settings and private attachments"`

---

### Task 8: AI clients, WhatsApp client, import/export and backup

**Files:**
- Harvest:
  - `app/Services/Ai/*`, `Console/Commands/AiPing.php`, `config/ai.php` (features `[]`, no `openai` block); the AI binding in `AppServiceProvider` (manifest C)
  - `Services/Client/WhatsAppClient.php` → `app/Services/WhatsApp/WhatsAppClient.php`, and `Services/Client/PhoneNumber.php` → `app/Services/WhatsApp/PhoneNumber.php`, both env-only (manifest A9); `config/whatsapp.php` + `default_country_code` (default `20`); `Console/Commands/WhatsAppTest.php`
  - `app/Support/IO/*` core (manifest A10: drop `int $companyId` everywhere; registry reads `config('io.resources', [])`; permissions via `permissionFor()` → `{permissionModule()}.view|edit`), `Jobs/IO/RunResourceImportJob.php`, `Models/ImportRun.php`, `ImportExportController.php` (index lists only the actor's own runs; manifest H7), `import-export/index.blade.php`, `config/io.php`
  - `Console/Commands/{RunBackup,GoogleDriveAuth}.php`, `Services/Backup/GoogleDriveClient.php`, `config/backup.php` (rebranded); the `backup:run` schedule in `routes/console.php`
- Create: migration `2026_10_15_000006_create_import_runs_table.php` (manifest B)
- Modify: `routes/web.php` (manifest D import-export routes under `import_export.view|edit`)
- Test (ported, fixtures as in Task 7):
  - `tests/Feature/Ai/{AnthropicClientTest,OpenRouterFreeModelsTest}.php`
  - `tests/Feature/IO/{ImportExportTest,QueuedResourceImportTest}.php`
  - `tests/Feature/Platform/BackupCommandTest.php`
  - `tests/Unit/CsvSanitizerTest.php`
  - new `tests/Unit/PhoneNumberTest.php`

**Interfaces:**
- Produces:
  - `App\Services\WhatsApp\WhatsAppClient` (`sendAuthCode`, as today)
  - `PhoneNumber::normalise(?string $raw): ?string` (E.164)
  - `ResourceSchema::toAttributes(array $row): array`, `resolveExisting(array $attrs): ?Model`, `inspectRow(array $attrs, ?Model $existing, int $rowNum): array`
  - the `OpenRouterClient` binding

- [ ] **Step 1: Port the tests and add** `PhoneNumberTest`:
  - `test_egyptian_local_number_gets_20`: `'01012345678'` → `'+201012345678'`
  - `test_arabic_indic_digits_normalise` (Review Focus 5): `'٠١٠١٢٣٤٥٦٧٨'` → `'+201012345678'`
  - `test_garbage_returns_null`
- [ ] **Step 2: Run** `php artisan test tests/Feature/Ai tests/Feature/IO tests/Unit`. Expected: FAIL.
- [ ] **Step 3: Harvest and edit.** `PhoneNumber` maps U+0660–U+0669 and U+06F0–U+06F9 to 0–9 before parsing.
- [ ] **Step 4: Run.** Expected: pass. Then run the full suite.
- [ ] **Step 5: Commit** `git commit -m "Add the AI and WhatsApp clients, import/export core and backups"`

---

### Task 9: Guard tests, CI, deploy files and CLAUDE.md

**Files:**
- Create:
  - `tests/Feature/Guards/SchemaGuardTest.php`
  - `tests/Feature/Guards/NoLeftoversTest.php`
  - `.github/workflows/ci.yml` (PHP 8.4, `postgres:16` service with the `alquds`/`alquds_test` credentials, `composer install`, `npm ci && npm run build`, `./vendor/bin/pint --test`, `php artisan test`)
  - `CLAUDE.md` (completes the Task 1 version): commands, the four registries and nine engines from the spec, the screen/role model, tests on PostgreSQL, the sub-project table. Keeps the standing rule and the gates from Task 1 unchanged.
  - `README.md` (local setup + the Render/Neon/R2 provisioning steps the user performs)
- Modify:
  - `docker/entrypoint.sh` (migrate + `RolesAndPermissionsSeeder` only)
  - `docker/supervisord.conf` (`--queue=default`)
  - `render.yaml` (Alquds env vars only)
  - harvest `tests/Unit/NoProcessLevelMemoTest.php`

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Write the failing guard tests**
  - `test_no_table_has_company_id`: `information_schema.columns` where `column_name = 'company_id'` is empty
  - `test_no_float_columns`: no `data_type` in (`real`, `double precision`)
  - `test_no_stored_balance_columns` (spec I1): no column named `balance`
  - `test_no_removed_code_is_referenced`: scan `app/ config/ routes/ resources/ lang/ database/ tests/` (excluding this test) for `CompanyScope|BelongsToCompany|CompanyContext|Employee|Village|Foundry|App\\Ats|Convo|Gate::before`, plus `insharo` case-insensitively → none found
- [ ] **Step 2: Run** `php artisan test tests/Feature/Guards`. Expected: PASS if Tasks 1–8 held the constraints; otherwise fix the offender (never the test).
- [ ] **Step 3: Write CI, deploy edits, `CLAUDE.md` and `README.md`.**
- [ ] **Step 4: Verify end to end**
  - `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoUsersSeeder`
  - `php artisan serve`, sign in as the demo manager, then as the demo system_admin (screenshot both sidebars with Playwright)
  - `./vendor/bin/pint --test && php artisan test` — all pass
  - push, and the GitHub Actions run is green
- [ ] **Step 5: Commit and push** `git commit -m "Add guard tests, CI on PostgreSQL, deploy files and project docs" && git push`

---

## Verification (whole sub-project)

1. `git clone` the new repo → `composer install && npm ci && npm run build && cp .env.example .env && php artisan key:generate && php artisan migrate --seed` succeeds against a local PostgreSQL.
2. `php artisan test` → green on PostgreSQL; `./vendor/bin/pint --test` → clean; the CI run on GitHub → green.
3. Manual check with Playwright screenshots:
   - Demo manager: the Arabic RTL navy/maroon shell with all six screens.
   - Demo system_admin: only users, roles, settings and audit log.
   - A no-role user: home only.
4. `git log` shows only the nine task commits, the first naming `erp@eaed57d`. Grep finds no INSHARO internal docs, lead data or staff names.
