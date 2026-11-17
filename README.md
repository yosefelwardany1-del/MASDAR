# MASDAR ERP

MASDAR's ERP. It starts as a copy of the starter ERP
([Empty-ERP](https://github.com/yosefelwardany1-del/Empty-ERP) at `88da6fd`): the platform
layer (sign-in, roles and screen permissions, audit log, settings, attachments, notifications,
AI and WhatsApp clients, import/export, backups) behind an Arabic RTL shell, single company.
MASDAR's business modules are built on it to MASDAR's spec.

- **What to build:** `docs/spec/PROJECT.md` (MASDAR's build spec — the authority; still to be
  written). `CLAUDE.md` § Starting a client lists what is set and what is still open.
- **How the code is organised and the rules it follows:** `CLAUDE.md`.
- **Designs and build plans:** `docs/superpowers/`.

## Local setup

Requirements: PHP 8.3+ with `pdo_pgsql`, `gd`, `zip`, `intl`, `bcmath`; Composer 2; Node 22; PostgreSQL 16
(the server **and** its `pg_dump`).

```bash
# 1. Databases (once): one for the app, one for the tests
sudo -u postgres psql -c "CREATE ROLE erp LOGIN PASSWORD 'erp' CREATEDB"
sudo -u postgres createdb -O erp erp
sudo -u postgres createdb -O erp erp_test

# 2. The app
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
php artisan migrate --seed                      # tables + the starter roles
php artisan db:seed --class=DemoUsersSeeder     # one user per role: {role}@example.test / password
php artisan db:seed --class=MasdarDemoSeeder    # example clients, PIs, orders and a container at sea
php artisan serve                               # http://localhost:8000

# 3. Checks (CI runs exactly these)
./vendor/bin/pint --test
php artisan test
```

A real user, outside the demo seeder:

```bash
php artisan user:create someone@example.com "Their Name" --role=manager --generate
```

`DemoUsersSeeder` refuses to run in production.

## Production: Render

Everything runs on **Render** from one Blueprint (`render.yaml`): the web service (one Docker
container: nginx + php-fpm + a queue worker + the scheduler, health check `/up`), its PostgreSQL 16
database, and a persistent disk for uploaded files. All three are in Singapore, Render's region
nearest Yiwu. One account, nothing to copy by hand.

### First deploy

1. Sign up at render.com with GitHub, and let Render's GitHub app see the `MASDAR` repository.
2. Choose a payment method: the web service (Standard), the database and the disk are paid plans.
3. **New → Blueprint**, pick the `MASDAR` repository, branch `main`, then **Apply**. Render
   creates `masdar-db`, then the `masdar` service, and deploys. Every deploy runs the
   migrations, `RolesAndPermissionsSeeder` and `MasdarReferenceSeeder`. These create the missing
   roles, permissions, lists, order steps, accounts and posting rules. They never undo a change
   made in the role editor, on Lists & options or on the Accounting screen.
4. When the deploy is live, open the service's **Shell** tab and create the first manager:
   `php artisan user:create you@example.com "Your Name" --role=manager --generate`.
   It prints a password; sign in at the service's `onrender.com` address and change it.
5. Sign in as that manager. Set the company names and logo on **Company settings**, the options
   on **Lists & options**, and the money accounts on **Payments**. Then add users.

Pushes to `main` deploy themselves once CI passes.

### Later, when wanted (the service's Environment tab)

- `APP_URL`: MASDAR's own domain, once it points at the service (Settings → Custom Domains).
- **Backups off Render** (see below): `BACKUP_DISK` with a bucket, or Google Drive. Until then
  the nightly `backup:run` refuses to write locally and logs a warning, and Render's own daily
  database backups are the safety net.
- A real mailer (`MAIL_MAILER`, `MAIL_HOST`, …) and `BACKUP_NOTIFY_EMAILS`.
- AI (`ANTHROPIC_API_KEY` or `OPENROUTER_API_KEY`), WhatsApp (`WHATSAPP_PHONE_NUMBER_ID`,
  `WHATSAPP_ACCESS_TOKEN`), Google sign-in (`SSO_GOOGLE_ENABLED`, `GOOGLE_SSO_CLIENT_ID`,
  `GOOGLE_SSO_CLIENT_SECRET`, `SSO_ALLOWED_DOMAINS`).

Credentials live in the environment only, never in the database.

**Reaching it from China:** the staff in Yiwu open the site from mainland China. Check early that
the `onrender.com` address loads there at a usable speed. If it doesn't, a custom domain helps
(and a domain on a CDN in front of it helps more).

## Backups

`backup:run` runs every night at 02:15 (`APP_TIMEZONE`): a `pg_dump` of the database
(`database.sql.gz`) and a zip of the private uploads (`uploads.zip`), in one folder per run,
named by its UTC date and time (`backups/2026-10-15_001500/`). Runs older than `BACKUP_RETENTION_DAYS` (30) are deleted.

**A backup must leave the machine it protects.** In production the command refuses a local
destination. Set at least one of:

- `BACKUP_DISK` — an S3-compatible disk (by default it follows `PRIVATE_DISK`, i.e. the R2
  bucket; a second bucket is safer);
- Google Drive — `BACKUP_GOOGLE_DRIVE=true` plus `GOOGLE_DRIVE_CLIENT_ID`,
  `GOOGLE_DRIVE_CLIENT_SECRET` and a refresh token from `php artisan backup:google-auth`.
  Publish the OAuth consent screen: while it is in "Testing", Google expires the token after
  7 days and backups stop.

Set `BACKUP_NOTIFY_EMAILS` so a failed night reaches a person — that needs a real mailer:
`render.yaml` ships `MAIL_MAILER=log`, which writes the warning to the log and mails nobody.
Run `php artisan backup:run` once by hand after setting it up.

### Restore

Restoring is deliberately manual. (Render also keeps its own daily backups of `masdar-db`,
restorable from the database's Recovery tab.) Rehearse it on a scratch database before you need it.

1. Download the run's folder (`database.sql.gz`, `uploads.zip`) from the bucket or Drive.
2. Create an empty database (a new Neon branch is ideal) and load the dump:
   `gunzip -c database.sql.gz | psql "postgresql://USER:PASSWORD@HOST/DATABASE?sslmode=require"`.
   The dump has no owners or grants, so it loads into any role.
3. Unzip `uploads.zip`. Each top-level folder is a disk name (`s3/` for the R2 bucket); copy
   what is inside it back into that disk under the same paths.
4. Point `DB_*` at the restored database, redeploy, and sign in to check the latest records
   and a few attachments.
