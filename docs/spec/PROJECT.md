# [Client name] — ERP build specification

> **Template.** Replace this file with the client's build spec, keeping the headings. Once
> filled in, this file is the authority for the build (`CLAUDE.md`): where anything else
> disagrees with it, it wins. Mark every decision the client has not made `OPEN` — the code
> builds it configurable and fails loudly rather than guessing.

## 0. Status

| Item | Value |
|---|---|
| Client | |
| Version / date | |
| Signed off by | |

## 1. Invariants

The rules that must never break, one per line, each to be held by PostgreSQL (a CHECK or a
trigger) and proven by a test before any module is built.

| # | Invariant |
|---|---|
| I1 | |

## 2. The business

Who the client is, what they sell or make, where they operate, the currencies, languages and
time zone they work in, and the size of the team.

## 3. Shared registries

The records every module reads from (parties, items, stock, the ledger, documents …), each with
its columns, who writes it and what may never change.

## 4. Shared engines

The services built once and used by every module (approvals, notifications, FX, numbering,
rate master, offline …): what each does and its rules.

## 5. Modules

One subsection per module: its screens, its workflow, the documents it issues, and the
registries it writes through which engine.

## 6. Roles and permissions

The roles (they replace the starter roles in `app/Security/Roles.php`), what each may see and
do per screen (`view` / `edit` / `approve`), what only a manager may approve, and what must be
hidden from whom (costs, prices, documents).

## 7. Reports and notifications

Each report and alert: its figures, who receives it, when, and through which channel.

## 8. Integrations

External systems (tax e-invoicing, payment, messaging, AI …), the credentials each needs (env
only), and what happens when one is down.

## 9. Data migration and go-live

What comes over from the old system, the opening balances and counts, and the cut-over plan.

## 10. Build order

The sub-projects in order; each gets its own spec → plan → build cycle and a human review
before the next starts.

## 11. Open questions

Every `OPEN` item above, with who must answer it and by when.

## 12. Readings

Decisions settled during the build from this spec (marked `INFERRED`) or with the client
(marked `DECIDED`), dated, one per row.

| Date | Mark | Reading |
|---|---|---|
