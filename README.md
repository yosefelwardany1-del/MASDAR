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

Requirements: PHP 8.3+ with `pdo_pgsql`, `gd`, `zip`, `intl`; Composer 2; Node 22; PostgreSQL 16
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

## Production: what you provision

The app runs on **Render** as one Docker service (`render.yaml`: nginx + php-fpm + a queue
worker + the scheduler, health check `/up`). It needs three accounts that only you can open.

### 1. Neon (PostgreSQL)

1. Create a project on PostgreSQL 16, region close to Render's (Frankfurt).
2. Copy the connection details into Render: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`,
   `DB_PASSWORD` (`DB_SSLMODE=require` is already set).
3. Every deploy runs `php artisan migrate --force` and `RolesAndPermissionsSeeder`. The seeder
   creates missing roles and permissions; it never undoes a change made in the role editor.

### 2. Cloudflare R2 (private files)

The container's disk is wiped on every deploy, so uploads must live in a bucket.

1. Create a bucket (private, no public access) and an API token with read/write on it.
2. Set in Render: `PRIVATE_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
   `AWS_BUCKET`, `AWS_ENDPOINT` (`https://<account>.r2.cloudflarestorage.com`, without the
   bucket name). `AWS_DEFAULT_REGION=auto` and `AWS_USE_PATH_STYLE_ENDPOINT=true` are set.

### 3. Render

1. New → Blueprint → this repository. Render reads `render.yaml`.
2. Fill every variable marked `sync: false`: `APP_URL`, the Neon and R2 values above,
   `MAIL_FROM_ADDRESS`, and optionally the AI and WhatsApp keys.
3. After the first deploy, create the first manager from the Render shell:
   `php artisan user:create you@example.com "Your Name" --role=manager --generate`.

Optional, all off until set: Google sign-in (`SSO_GOOGLE_ENABLED`, `GOOGLE_SSO_CLIENT_ID`,
`GOOGLE_SSO_CLIENT_SECRET`, `SSO_ALLOWED_DOMAINS`), AI (`ANTHROPIC_API_KEY` or
`OPENROUTER_API_KEY`), WhatsApp (`WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`).
Credentials live in the environment only, never in the database.

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

Restoring is deliberately manual. Rehearse it on a scratch database before you need it.

1. Download the run's folder (`database.sql.gz`, `uploads.zip`) from the bucket or Drive.
2. Create an empty database (a new Neon branch is ideal) and load the dump:
   `gunzip -c database.sql.gz | psql "postgresql://USER:PASSWORD@HOST/DATABASE?sslmode=require"`.
   The dump has no owners or grants, so it loads into any role.
3. Unzip `uploads.zip`. Each top-level folder is a disk name (`s3/` for the R2 bucket); copy
   what is inside it back into that disk under the same paths.
4. Point `DB_*` at the restored database, redeploy, and sign in to check the latest records
   and a few attachments.
