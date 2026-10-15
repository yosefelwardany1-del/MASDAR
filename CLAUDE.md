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
php artisan test            # PostgreSQL only (see phpunit.xml) — never SQLite
./vendor/bin/pint           # before every commit
```

Tests need a local PostgreSQL 16 with role `alquds` / password `alquds` and a database
`alquds_test`.

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
