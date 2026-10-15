# Al-Quds ERP — Build Specification

Authoritative build spec for the ERP replacing manual/Excel operations at Al-Quds (Cairo: publishing, printing, book export, and freight consolidation). Arabic RTL interface, English codebase.

**Sources:** 12 client requirement documents, a discovery meeting transcript, 50 answered scoping questions, a formal approved-decisions document, and 37 real operational files (container case files, customs papers, packing lists in every format, the 2025 warehouse journal, worked cost estimates).

**Precedence:** where sources conflict, this file wins. Where it says `OPEN`, build the configurable path and do not guess.

### How to read the status of any statement here

| Marker | Meaning |
|---|---|
| (unmarked) | The client decided it, or it is evidenced in a real document they supplied. Treat as fixed. |
| `ENGINEERING` | Our choice, not theirs. Change it if you have a better reason — just change it deliberately. |
| `INFERRED` | Our reading of what they said, not something they said. Worth one sentence of confirmation. |
| `OPEN` | Genuinely unknown. Build configurable, fail loudly, never default silently. |
| `NOT IN SCOPE` | Specified here but excluded from the current build. Do not implement. |

Last reconciled against client sources: **15 October 2026**.

---

## 0. The business

### 0.1 Two activities under one company

| Activity | What happens | Revenue |
|---|---|---|
| **Freight consolidation** (شحن) | Receives cartons from Egyptian and foreign bookshops on behalf of overseas customers, consolidates into shared containers, exports | Flat commission per container + margin on buy-on-behalf |
| **Publishing & manufacturing** (تصنيع) | Imports paper, outsources printing and binding to external vendors, sells finished books and mushaf locally and for export | Product margin |

One container may carry goods from both. Financial reporting separates the two. **Every ledger and stock row carries an `activity` tag.**

### 0.2 Five sources of goods

1. Imported raw material (paper from Turkey, rolls, by ton)
2. Locally purchased raw material
3. Locally purchased finished goods for resale
4. Goods manufactured via outsourced vendors (print → bind → receipt)
5. Third-party goods held only for shipping — **never owned**

### 0.3 Four shipping service models

| Model | Company role | Revenue logic |
|---|---|---|
| Ship only | Customer buys from bookshops and sends to the warehouse | Freight commission |
| Buy-on-behalf + ship | Company buys for the customer, then ships | **Spread**: customer invoiced at normal discount, company receives ~5% larger discount from the bookshop |
| Manufacture + ship | Company's own product | Product margin + freight |
| **Freight-inclusive pricing** | Unit price quoted "شامل الشحن" | Freight embedded in unit price, no separate claim (seen on the Yemen packing list) |

### 0.4 Scale (2025 actuals, from their own warehouse journal)

3,354,875 printed units inbound · 145 containers (131× 20ft, 13× 40ft) · 32,588 outbound packages · ~500 packages scanned per container.

Mix: mushaf 79%, other books 11.2%, juz/ahzab 7.3%, adhkar 2.5%.
Markets by containers: Somalia 39, Ethiopia 15, Kenya 15, Yemen 14, Algeria 13, Nigeria 11, Senegal 6, Mauritania 6, Chad 5, Guinea 4, Palestine 4, Morocco 3. Also Libya (road freight), Djibouti, South Africa.
Supplier concentration: top 2 vendors = 64% of units, top 6 = 88.6%.

**Raw paper is also a sellable product.** Some customers buy paper, not books. Do not model raw material as consumable-only.

### 0.5 Why the customer-facing layer matters

In publishing, Al-Quds has digital competitors (Dar El Shorouk, المصرية اللبنانية, دار السلام) — though those compete in novels, not the heritage/religious segment Al-Quds occupies.

**In freight consolidation, no competitor runs any digital system at all.** The whole market is manual. The customer portal and WhatsApp agent (§7.13, §7.16) are therefore not conveniences — they are the differentiator, and the client named them as such.

---

## 1. Non-negotiable invariants

Enforced by database constraints and tests, not convention. A PR violating one does not merge. **Write these as failing tests in `tests/invariants/` before any module.**

| # | Invariant | Enforcement |
|---|---|---|
| I1 | Balances are never stored. Every balance is `SUM()` over rows. | No `balance` column on any table. Test asserts the schema. |
| I2 | Approved documents are immutable. | Trigger blocks `UPDATE` when `status='approved'`. Corrections are new rows. |
| I3 | Ledger corrections are additive. | No `DELETE` on `ledger_entries`. Reversal = new row with `reverses_id`. |
| I4 | Every mutation is attributed. | `created_by`, `created_at` NOT NULL everywhere. Audit trigger on all writes. |
| I5 | Every financial and stock row carries an activity tag. | `activity` enum NOT NULL on `ledger_entries`, `stock_movements`. |
| I6 | Every monetary row stores original currency, FX rate, base amount. | Three NOT NULL columns. Historic rows never re-translated. |
| I7 | Stock quantity is signed and append-only. | No in-place adjustment. Corrections are new movements. |
| I8 | One party = one record regardless of role count. | Unique identity constraint; roles are rows. |
| I9 | A reservation is never a stock movement. | No `stock_movements` row references a reservation. Test: reserving changes no balance. |
| I10 | Cost, price and profit are invisible to non-managers at the query layer. | Non-manager roles read views that omit those columns. Not UI hiding. |
| I11 | No self-approval. | `approval_requests.requested_by <> decided_by`, enforced by CHECK. |

---

## 2. Stack & conventions

`ENGINEERING` — the whole of this section is our choice, not a client requirement. The client specified only cloud hosting, with server, backup, maintenance and audits delivered by INSHARO as part of the finished product.

| Layer | Choice | Why |
|---|---|---|
| Database | PostgreSQL 16 (Neon in production; 15+ required) | Transactions, `jsonb`, generated columns, row triggers. Non-negotiable. |
| Backend | Laravel 12 (PHP 8.3+) | Harvested from the INSHARO ERP platform layer: sign-in, roles, audit, attachments, notifications, AI and WhatsApp clients already proven in production |
| ORM | Eloquent with explicit migrations | Explicit migrations. Never auto-sync in production. Invariants live in PostgreSQL triggers and CHECKs, never only in PHP |
| Frontend | Server-rendered Blade; small vanilla-JS PWA pages for the offline scanner and POS | — |
| Styling | Bootstrap 5.3 RTL build, logical `ms-`/`me-` utilities, `dir="rtl"` on root | RTL proven in production in the INSHARO ERP. See §13 |
| Offline | IndexedDB queue + idempotency keys | Mandatory, see §4.8 |
| Auth | Session cookies + server-side RBAC | Client checks are UX only, never the gate |
| Money | `numeric(18,4)` | Never float, never JS `number` |
| Quantities | `numeric(18,4)` | Sheets per copy can be 19.5 and must never round |
| Base currency | EGP | USD and EUR also in active use |

**Naming:** tables plural snake_case, columns snake_case, enums as text + CHECK (not Postgres enums — they are painful to alter).

---

## 3. Kernel — four registries

Everything reads from these. No module keeps its own balance, price, or status.

### 3.1 `parties`

One entity, many roles. A bookshop can simultaneously be customer, supplier and shipment source.

```sql
CREATE TABLE parties (
  id               bigserial PRIMARY KEY,
  code             text UNIQUE NOT NULL,
  name_ar          text NOT NULL,
  name_en          text,
  country          text,
  city             text,
  commercial_reg   text,        -- REQUIRED for official invoices
  tax_number       text,        -- REQUIRED for customs clearance
  customs_code     text,        -- client code with Egyptian customs
  official_address text,
  credit_limit     numeric(18,4),
  status           text NOT NULL DEFAULT 'active',  -- active|blocked|restricted
  blocked_reason   text,
  blocked_at       timestamptz,
  created_by       bigint NOT NULL REFERENCES users(id),
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE party_roles (
  party_id bigint NOT NULL REFERENCES parties(id),
  role     text   NOT NULL,
  -- customer | supplier | service_vendor | sending_library | agent
  -- | exhibition | author | customs_broker | shipping_line | haulier
  PRIMARY KEY (party_id, role)
);

CREATE TABLE party_contacts (
  id       bigserial PRIMARY KEY,
  party_id bigint NOT NULL REFERENCES parties(id),
  kind     text NOT NULL,  -- phone_local|phone_home_country|whatsapp|email
  value    text NOT NULL,
  is_primary boolean NOT NULL DEFAULT false
);

-- Representatives change often. History, never overwritten.
CREATE TABLE party_representatives (
  id         bigserial PRIMARY KEY,
  party_id   bigint NOT NULL REFERENCES parties(id),
  name       text NOT NULL,
  phone      text,
  purpose    text,          -- financial follow-up | collection | …
  start_date date NOT NULL,
  end_date   date           -- NULL = active; multiple active reps allowed
);

CREATE TABLE party_attachments (
  id          bigserial PRIMARY KEY,
  party_id    bigint NOT NULL REFERENCES parties(id),
  kind        text NOT NULL,   -- passport | national_id | photo | other
  storage_key text NOT NULL,
  uploaded_by bigint NOT NULL REFERENCES users(id),
  uploaded_at timestamptz NOT NULL DEFAULT now()
);

-- Every READ of a party_attachment is logged, not just writes. See §4.9.
CREATE TABLE attachment_access_log (
  id            bigserial PRIMARY KEY,
  attachment_id bigint NOT NULL REFERENCES party_attachments(id),
  user_id       bigint NOT NULL REFERENCES users(id),
  accessed_at   timestamptz NOT NULL DEFAULT now()
);
```

**Critical distinction (approved decision 1):** `sending_library` is an **operational** party — it has packing lists, packages, authorisations and official approvals, but **no ledger account**. `agent` is the **financial** party — it has an account and a balance. In one shared container these are different entities. Do not merge them.

**Rules**
- Duplicate detection on phone / WhatsApp / name before creating a party — warn, do not hard-block.
- Customer ledger and supplier ledger for the same party are separate. **No automatic offsetting.** Manual offsetting requires explicit manager approval via `approval_requests`.

### 3.2 `stock_movements`

Append-only. The single source of every quantity.

```sql
CREATE TABLE locations (
  id       bigserial PRIMARY KEY,
  code     text UNIQUE NOT NULL,
  name_ar  text NOT NULL,
  kind     text NOT NULL,  -- warehouse|at_service_vendor|at_exhibition|in_transit
  party_id bigint REFERENCES parties(id)  -- set when vendor/exhibition
);
-- Seed warehouses: Obour main, Obour second, Darrasa (8 Gohar St — also the office)

CREATE TABLE stock_movements (
  id             bigserial PRIMARY KEY,
  edition_id     bigint NOT NULL REFERENCES editions(id),
  location_id    bigint NOT NULL REFERENCES locations(id),
  ownership      text NOT NULL,
  -- company_owned | customer_custody | at_service_vendor | at_exhibition
  owner_party_id bigint REFERENCES parties(id),  -- required when ownership<>company_owned
  qty            numeric(18,4) NOT NULL,         -- signed: + in, - out
  uom            text NOT NULL,                  -- piece|ream|sheet|roll|kg|ton
  unit_cost      numeric(18,4),                  -- weighted average at movement time
  movement_type  text NOT NULL,
  -- receipt | issue | transfer_out | transfer_in | custody_out | custody_return
  -- | count_adjust | exhibition_out | exhibition_return | damage | author_copies
  activity       text NOT NULL,                  -- shipping | manufacturing
  carrier_party_id bigint REFERENCES parties(id),-- haulier, when relevant
  driver_name    text,                           -- their journal records السائق
  ref_doc_type   text,
  ref_doc_id     bigint,
  created_by     bigint NOT NULL REFERENCES users(id),
  created_at     timestamptz NOT NULL DEFAULT now(),
  CHECK (ownership = 'company_owned' OR owner_party_id IS NOT NULL)
);

CREATE INDEX ON stock_movements (edition_id, location_id, ownership);
CREATE INDEX ON stock_movements (ref_doc_type, ref_doc_id);
```

**The only way to get a quantity:**
```sql
SELECT COALESCE(SUM(qty),0) FROM stock_movements
WHERE edition_id = $1 AND location_id = $2 AND ownership = 'company_owned';
```

**Units of measure are mixed and real:** finished goods in pieces, paper in reams or sheets (coated paper is counted in فرخ, uncoated in رزمة), imported paper in rolls and tons, customs weights in kg. `uom` is per movement, not per item.

**Valuation: weighted average**, recomputed on every inbound receipt:
```
new_avg = (existing_qty * existing_avg + received_qty * landed_cost) / (existing_qty + received_qty)
```

### 3.3 `ledger_entries`

Party ledger plus cash movement. **Not full double-entry** — the client's accountant keeps statutory books separately (their explicit decision).

```sql
CREATE TABLE ledger_entries (
  id            bigserial PRIMARY KEY,
  party_id      bigint NOT NULL REFERENCES parties(id),
  party_role    text NOT NULL,          -- customer | supplier (separate books)
  entry_date    date NOT NULL,
  debit         numeric(18,4) NOT NULL DEFAULT 0,
  credit        numeric(18,4) NOT NULL DEFAULT 0,
  currency      text NOT NULL,           -- EGP | USD | EUR
  fx_rate       numeric(18,6) NOT NULL,  -- at recording time; never re-translated
  amount_base   numeric(18,4) NOT NULL,  -- EGP
  activity      text NOT NULL,           -- shipping | manufacturing
  container_id  bigint REFERENCES containers(id),  -- cost centre, see §12
  job_order_id  bigint REFERENCES job_orders(id),  -- for future title profitability
  edition_id    bigint REFERENCES editions(id),    -- for future title profitability
  entry_type    text NOT NULL,
  -- sales_invoice | payment_received | purchase_invoice | payment_made
  -- | discount | credit_note | debit_note | opening_balance | freight_claim
  -- | demurrage | fx_difference | author_settlement | commission
  ref_doc_type  text,
  ref_doc_id    bigint,
  reverses_id   bigint REFERENCES ledger_entries(id),
  note          text,
  created_by    bigint NOT NULL REFERENCES users(id),
  created_at    timestamptz NOT NULL DEFAULT now(),
  CHECK (debit = 0 OR credit = 0)
);

CREATE TABLE cash_accounts (
  id       bigserial PRIMARY KEY,
  name     text NOT NULL,
  currency text NOT NULL,
  kind     text NOT NULL   -- bank | cash | temporary_exhibition
);

CREATE TABLE cash_movements (
  id              bigserial PRIMARY KEY,
  account_id      bigint NOT NULL REFERENCES cash_accounts(id),
  direction       text NOT NULL,   -- in | out
  amount          numeric(18,4) NOT NULL,
  currency        text NOT NULL,
  fx_rate         numeric(18,6) NOT NULL,
  amount_base     numeric(18,4) NOT NULL,
  ledger_entry_id bigint REFERENCES ledger_entries(id),
  activity        text NOT NULL,
  created_by      bigint NOT NULL REFERENCES users(id),
  created_at      timestamptz NOT NULL DEFAULT now()
);
```

**Customer balance — the only way to get it:**
```sql
SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM ledger_entries
WHERE party_id = $1 AND party_role = 'customer';
```
Positive = customer owes. **This single query feeds credit limits, collections, and the shipping financial lock. No module computes it any other way.**

`UPDATE` and `DELETE` are blocked by trigger. Reversal creates a new row with `reverses_id`.

**Why `container_id`, `job_order_id` and `edition_id` are on every line:** approved decision 2 defers title-level profitability but requires that it can be added later without a rebuild. Record the links now even though reports only use `container_id` today.

### 3.4 `documents`

```sql
CREATE TABLE documents (
  id          bigserial PRIMARY KEY,
  doc_type    text NOT NULL,       -- see §11 for the full list
  doc_number  text NOT NULL,
  party_id    bigint REFERENCES parties(id),
  status      text NOT NULL DEFAULT 'draft',  -- draft|approved|void
  version     int NOT NULL DEFAULT 1,
  activity    text NOT NULL,
  payload     jsonb NOT NULL,
  approved_by bigint REFERENCES users(id),
  approved_at timestamptz,
  created_by  bigint NOT NULL REFERENCES users(id),
  created_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (doc_type, doc_number)
);

CREATE TABLE document_attachments (
  id          bigserial PRIMARY KEY,
  document_id bigint NOT NULL REFERENCES documents(id),
  kind        text NOT NULL,
  storage_key text NOT NULL,
  uploaded_by bigint NOT NULL REFERENCES users(id),
  uploaded_at timestamptz NOT NULL DEFAULT now()
);
```

Trigger blocks `UPDATE` when `status='approved'`, except transition to `void`. Voiding requires an approval request and never deletes.

**Invoice payload must carry Egyptian Tax Authority (ETA) e-invoicing fields from day one** even though integration is deferred. Absent fields mean rebuilding the document later. Include: seller/buyer tax IDs, item codes (GS1/EGS), unit codes, tax subtotals, invoice UUID placeholder.

---

## 4. Shared engines — nine

Build these before any module. Each serves several modules; building them per-module is the main failure mode of this project. The requirement documents describe custody in 4 places, unexplained variance in 5, and the financial lock in 3 — each is **one** engine.

### 4.1 Rate master

Central lookup with effective dating. Never a single value per item.

```sql
CREATE TABLE rate_items (
  id             bigserial PRIMARY KEY,
  rate_type      text NOT NULL,
  -- paper_price_per_ton | ream_weight | print_price_per_signature
  -- | plate_price | binding_price_per_volume | cellophane_price
  -- | pages_per_signature | pages_per_full_sheet | sheets_per_ream
  -- | waste_pct | fixed_job_cost | container_commission | category_margin_pct
  lookup_key     jsonb NOT NULL,  -- e.g. {"paper_type":"كريمي","grammage":70}
  value          numeric(18,4) NOT NULL,
  currency       text,
  effective_from date NOT NULL,
  effective_to   date,            -- NULL = current
  created_by     bigint NOT NULL REFERENCES users(id),
  created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ON rate_items (rate_type, effective_from DESC);
```

- Old rates are never deleted, only closed with `effective_to`.
- A change beyond a configurable % raises a warning before save.
- **Simulation before commit:** show every edition whose cost changes and by how much, before writing the rate.
- Quotations and job orders **snapshot** the rate at agreement time. Later changes never alter an agreed price.

### 4.2 FX

```sql
CREATE TABLE fx_rates (
  currency     text NOT NULL,
  rate_date    date NOT NULL,
  rate_to_base numeric(18,6) NOT NULL,
  source       text,            -- manual | cbe_bulletin
  entered_by   bigint NOT NULL REFERENCES users(id),
  PRIMARY KEY (currency, rate_date)
);
```
Daily rate entered **manually** (their decision). Alert when today's rate is missing. Optional external suggestion (Central Bank bulletin) — never auto-applied. Base = EGP. Historic ledger rows keep their original rate forever.

**Real rates seen on their documents:** USD 49.7703 (July 2026 export), USD 52.0174 (June 2026 import). EUR used for some ocean freight.

FX difference between invoice date and payment date posts as a **separate `fx_difference` ledger line**, never an edit to the invoice.

### 4.3 Dual calendar

Hijri is **sighting-based (الرؤية الشرعية)**, so it cannot be computed forward reliably.
```sql
CREATE TABLE hijri_months (
  hijri_year      int NOT NULL,
  hijri_month     int NOT NULL,
  gregorian_start date NOT NULL,
  confirmed       boolean NOT NULL DEFAULT false,  -- false = calculated estimate
  confirmed_by    bigint REFERENCES users(id),
  PRIMARY KEY (hijri_year, hijri_month)
);
```
Show a calculated default; require manual confirmation to mark authoritative. Seasonal demand analysis (Ramadan patterns) reads this table.

### 4.4 Notifications

One engine: `event → template → channel`. Not per-feature implementations.

```sql
CREATE TABLE notification_events (
  id           bigserial PRIMARY KEY,
  event_key    text NOT NULL,
  party_id     bigint REFERENCES parties(id),
  user_id      bigint REFERENCES users(id),
  payload      jsonb NOT NULL,
  channel      text NOT NULL,   -- whatsapp | email | in_app
  lang         text NOT NULL DEFAULT 'ar',  -- ar | en | fr
  status       text NOT NULL DEFAULT 'pending',
  snooze_until timestamptz,
  dismissed_reason text,
  created_at   timestamptz NOT NULL DEFAULT now()
);
```

Events: `package_received`, `payment_due`, `promise_broken`, `reorder_point_hit`, `reservation_pulled`, `churn_alert`, `discrepancy_raised`, `fx_rate_missing`, `document_pending`, `job_order_delayed`, `repeated_demand_on_pending_item`, `partial_shipment_remainder`, `po_overdue`, `approval_pending`, `custody_aging`, `rights_expiring`.

**Snooze and reschedule are required**, not optional — the client asked explicitly for reminders that can be deferred to a chosen date. Per-party preference centre, daily send cap, timezone awareness (customers across Africa), multilingual templates (Arabic / French / English).

### 4.5 Discrepancy

One engine for five situations: inter-warehouse transfer variance, loss at a service vendor, exhibition settlement variance, packing list vs order quantity, container door-scan mismatch.

```sql
CREATE TABLE discrepancies (
  id           bigserial PRIMARY KEY,
  context      text NOT NULL,
  ref_doc_type text, ref_doc_id bigint,
  edition_id   bigint REFERENCES editions(id),
  expected_qty numeric(18,4),
  actual_qty   numeric(18,4),
  variance_pct numeric(8,4),
  status       text NOT NULL DEFAULT 'open',  -- open|approved|written_off
  resolution   text,
  approved_by  bigint REFERENCES users(id),
  approved_at  timestamptz
);
```
Threshold is **configurable**, currently **0.5%** for stock counts. Below → auto-accept. Above → manager approval. Escalate if the same order is affected more than twice.

> Operational warning to raise with the client: 0.5% across three warehouses never counted before will generate constant escalations in the first months. Review after three count cycles.

### 4.6 Approvals

Generic request/approve flow. **Self-approval is forbidden absolutely** (I11), even when one user holds both roles.

```sql
CREATE TABLE approval_requests (
  id           bigserial PRIMARY KEY,
  kind         text NOT NULL,          -- see §10.3 for the 13 kinds
  ref_doc_type text, ref_doc_id bigint,
  payload      jsonb NOT NULL,
  requested_by bigint NOT NULL REFERENCES users(id),
  status       text NOT NULL DEFAULT 'pending',
  decided_by   bigint REFERENCES users(id),
  decided_at   timestamptz,
  decision_note text,
  CHECK (decided_by IS NULL OR decided_by <> requested_by)
);
```

### 4.7 Barcode

**The barcode encodes the package number only.** Contents are looked up from the packing list — one package routinely holds many titles. Do not encode contents.

Label content: sending library, receiving customer, shipment number, sequential package number of total.

**Some sending publishers already print EAN-13 barcodes per line** (observed on a real inbound list). Capture those as existing item codes for third-party goods rather than minting new ones.

### 4.8 Offline sync — mandatory, not an enhancement

~500 packages scanned per container inside warehouses with poor connectivity; exhibition POS runs abroad.

- Client queues writes in IndexedDB with a client-generated `idempotency_key` (UUID).
- Server dedupes on `idempotency_key`; replay is safe.
- UI shows queue depth and sync state at all times.
- Conflict policy: stock movements are append-only so they merge naturally. Document status changes use last-write-wins with a conflict log.

### 4.9 Audit log

Trigger-based on every table: `{table, row_id, action, before, after, user_id, at}`.

Separately log every **read** of `party_attachments` — passport and national-ID scans.

---
## 5. Product catalogue — two levels

The catalogue separates **Title** (العنوان) from **Edition** (الطبعة). A flat item table is wrong.

```sql
CREATE TABLE item_categories (
  id         bigserial PRIMARY KEY,
  code       text UNIQUE NOT NULL,
  name_ar    text NOT NULL,
  -- مصاحف | كتب دينية | كتب تعليمية | كتب أدبية عامة | مواد خام
  margin_pct numeric(8,4)   -- OPEN: values not supplied
);

CREATE TABLE titles (
  id          bigserial PRIMARY KEY,
  title_no    int UNIQUE NOT NULL,     -- the 0142 in BK-0142-02
  name_ar     text NOT NULL,
  author      text,                    -- المؤلف
  muhaqqiq    text,                    -- المحقق (editor/verifier)
  publisher   text,                    -- دار النشر
  riwaya      text,                    -- mushaf only: حفص | ورش | …
  category_id bigint NOT NULL REFERENCES item_categories(id),
  family      text NOT NULL,           -- book | mushaf | raw_material
  created_by  bigint NOT NULL REFERENCES users(id),
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE editions (
  id            bigserial PRIMARY KEY,
  title_id      bigint NOT NULL REFERENCES titles(id),
  edition_no    int NOT NULL,
  sku           text UNIQUE NOT NULL,
  -- [type]-[title#]-[edition#]: BK-0142-02 | MSH-0007-01 | RM-PAPER-70G-A4
  size          text,       -- 20×14 24×17 17×12 12×8 14×10 35×25 28×20 10×7 35×50
  paper_type    text,       -- كريمي | نباتي | أبيض | جرنال | جرنال كريمي
  grammage      int,
  binding_type  text,       -- سلوفان | فني | غلاف | سوستة
  layout        text,       -- نصف محير | ثمن محير | جوامعي | ربع …
  print_colors  int,
  page_count    int,
  volumes       int NOT NULL DEFAULT 1,
  cover_design  text,
  sellable      boolean NOT NULL DEFAULT true,
  base_uom      text NOT NULL DEFAULT 'piece',  -- piece|ream|sheet|roll|kg|ton
  hs_code       text,       -- varies by market, see §7.10
  ean13         text,       -- when the sender already barcodes it
  min_stock     numeric(18,4),
  lead_time_days int,
  UNIQUE (title_id, edition_no)
);
```

**Title-level fields are inherited, never repeated.** Author, muhaqqiq, publisher, category and riwaya are written once.

### 5.1 The rule that controls edition count

A new **edition** code is created *only* when a real specification changes: size, paper type, grammage, binding, colours, page count, or cover design. Reprinting the same book to the same spec is **not** a new edition — it is a new **job order** against the existing edition.

| Case | Edition code | Job order |
|---|---|---|
| صحيح البخاري, size X, white paper, carton binding — first print | `BK-0001-01` | JO #45 (2024, 5,000 copies) |
| Exact same spec, reprinted later | same `BK-0001-01` | JO #78 (2025, 3,000 copies) |
| Same book, different size | new `BK-0001-02` | new JO |

**Consequently print year is NOT an edition attribute** — it changes with every reprint. Store it on the job order and pull it from the job order linked to the shipment or sales order when it must be displayed.

### 5.2 Raw materials

A separate family with different units (ream, sheet, roll, kg, ton — not piece): printing paper (size, grammage, quality, country of origin), ink (type, colour, origin), بروكين and others.

**Raw paper is sellable.** `sellable = true` with dual pricing: true cost when consumed in manufacturing, manually entered selling price when sold directly. Deducted from the **same** production stock balance — no separate balance.

### 5.3 Ownership

Every edition and raw material movement carries ownership: `company_owned` or custody for a named party (§3.2).

---

## 6. Costing engine

**The engine is signature-based (ملزمة). The signature stays in the model.**

> An earlier draft of this spec said to drop ملزمة entirely. That was wrong. The client's simplification (storing `pages_per_full_sheet`) applies to the **paper** calculation only. **Plate count and print price both derive from signatures.**

### 6.1 Printers price two different ways — support both

**Client clarification, 12 October:** some printers charge **per signature (ملزمة)** and others charge **per ream (رزمة)**. The cost engine must support both, selected **per printer**. This settles the earlier confusion about whether the signature belongs in the model: it does, and so does the ream.

```sql
ALTER TABLE parties ADD COLUMN print_pricing_method text;
-- per_signature | per_ream   -- required when the party has role 'service_vendor' doing printing
```

Plate count is **always** signature-based regardless of how the printer bills, because plates are a physical fact of the imposition, not a commercial term.

### 6.2 Formulas

```
-- signatures (always computed; drives plates and, for some vendors, print price)
signatures       = volume_page_count / pages_per_signature

-- paper
sheets_per_copy  = volume_page_count / pages_per_full_sheet   -- NEVER rounded
total_sheets     = sheets_per_copy * quantity
base_reams       = total_sheets / sheets_per_ream             -- may be fractional
waste_reams      = ceil(base_reams * waste_pct)               -- waste rounds UP to whole reams
actual_reams     = base_reams + waste_reams

ream_weight_kg   = f(sheet_size, grammage)
ream_price       = ream_weight_kg * (price_per_ton / 1000)    -- paper is bought BY THE TON
paper_cost       = actual_reams * ream_price

-- printing: branch on the vendor's billing method
IF vendor.print_pricing_method = 'per_signature':
    print_units  = signatures * quantity / 1000
    print_cost   = print_units * price_per_signature_per_1000
ELSE:  -- per_ream
    print_cost   = actual_reams * price_per_ream

-- plates: always signature-based
plate_count      = signatures * print_colors
plate_cost       = plate_count * plate_price

-- binding & finishing
binding_cost     = binding_price_per_volume * volumes * quantity
cellophane_cost  = cellophane_price * quantity                -- when applicable

-- totals
job_total        = sum(all cost lines)
cost_per_copy    = job_total / quantity
```

**Paper is priced per ton**, so `ream_price` is derived from ream weight — not a direct lookup. Store `price_per_ton` in the rate master keyed on paper type + grammage.

**19.5 sheets per copy must never round.** Only the *waste* portion rounds, and it rounds up to a whole ream.

#### Why the divisors are what they are

The client's documents give the formulas with the divisors cut off. Both are **derived and cross-checked against their own two worked examples** rather than assumed:

```
pages_per_signature  = 16    (their fold factor)
pages_per_full_sheet = 32    (two signatures per sheet — 624/32 = 19.5 exactly)
sheets_per_ream      = 500

=> reams can be computed either way and agree:
   via sheets:     19.5 x 25,000 / 500        = 975
   via signatures: 39 x 25,000 / 1000         = 975
   (the /1000 is simply 2 signatures per sheet x 500 sheets per ream)
```

**Independent confirmation from the second example:** عمدة الأحكام is 96 pages → 96/16 = **6 signatures**; at 2 colours that is 6 × 2 = **12 plates**, and their estimate says exactly "الزنكات (12)". The signature model is therefore confirmed by a number we did not fit to it.

Implied unit rates in that example, useful as sanity anchors: plate 300 EGP each (3,600/12); printing 200 EGP per signature per 1,000 copies (6,000 / (6 × 5,000 / 1,000)).

`OPEN` — `pages_per_signature` is 16 in both supplied examples but may vary by press and imposition. Store it per edition in the rate master, defaulting to 16, and never hard-code it.

### 6.3 Cost lines — the four-term formula is incomplete

A real مقايسة carries eleven lines. Model these as a **table on the job order**, not fixed columns:

```sql
CREATE TABLE job_order_cost_lines (
  id           bigserial PRIMARY KEY,
  job_order_id bigint NOT NULL REFERENCES job_orders(id),
  line_type    text NOT NULL,
  -- paper | internal_printing | plates | basic_binding | cellophane_and_cover
  -- | editing | proofreading | typesetting | design | author_rights | transport
  amount       numeric(18,4) NOT NULL,
  currency     text NOT NULL DEFAULT 'EGP',
  is_estimate  boolean NOT NULL DEFAULT true,
  vendor_party_id bigint REFERENCES parties(id),
  created_by   bigint NOT NULL REFERENCES users(id),
  created_at   timestamptz NOT NULL DEFAULT now()
);
```

### 6.4 Selling price — two methods, both required

```
markup_on_cost:     price = cost_per_copy * (1 + pct)
margin_from_price:  price = cost_per_copy / (1 - pct)      -- the method used in practice
```

Also compute **break-even**: copies needed to cover edition cost, and that as a % of the run.

### 6.5 Golden tests

**Test A — the real worked estimate (مقايسة عمدة الأحكام, 29 Sep 2026):**
5,000 copies, 1 volume, 96 pages.

| Line | EGP |
|---|---|
| الورق | 33,264.00 |
| الطباعة الداخلية | 6,000.00 |
| الزنكات (12 plates) | 3,600.00 |
| التجليد الأساسي | 10,000.00 |
| **إجمالي تكلفة الطبعة** | **52,864.00** |
| **تكلفة النسخة** | **10.57** |

At 30% margin-from-price: selling price **15.10**, profit/copy **4.53**, total profit **22,656**, profit on cost **42.86%**, break-even **3,500 copies = 70%** of the run.
Assert all of:
```
signatures      == 6          # 96 / 16
plate_count     == 12         # 6 x 2 colours — matches their estimate exactly
edition_total   == 52864.00
cost_per_copy   == 10.57
round(10.57 / (1 - 0.30), 2) == 15.10
break_even_copies == 3500     # 70% of the run
```

**Test B — the sheet calculation (from their costing document):**
624 pages, fold factor 16, qty 25,000, waste 3% → 39 signatures, **19.5 sheets per copy**, 487,500 total sheets, 975 net reams, ≈1,004 actual reams.
Assert `19.5` is stored and compared without rounding.

### 6.6 Rules

- Missing ream price for a new paper combination → **block save with an alert** until entered manually.
- Proposed selling price requires **manager approval** before customers see it. Live quotes and open job orders keep their agreed price.
- Selling below cost requires manager approval.
- The client already runs a web calculator at `quds-book-cost.dar-quds.chatgpt.site` — align terminology and output with it.

### 6.7 `OPEN` in costing

| Item | Status |
|---|---|
| Paper type → waste % table | 3–10% range known; per-type values not supplied |
| Fixed-cost allocation across titles in one job order | Config enum, default per-signature |
| Category margin % | Categories defined, percentages not supplied |
| Rate master values (price/ton, price/signature, plate, binding) | Formula known, current values not supplied |

---

## 7. Modules — seventeen

Modules hold screens and workflow logic. They hold **no balances, prices, or statuses of their own**.

| # | Module | Owns | Reads from |
|---|---|---|---|
| 1 | Parties | party CRUD, rep history, credit limits, attachments | §3.1 |
| 2 | Catalogue | titles, editions, categories, SKUs | §5 |
| 3 | Pricing & Costing | cost engine, rate master, proposed selling price | §4.1, §6 |
| 4 | BOM & Planning | per-edition components, reorder points, lead-time capture | §3.2, §4.1 |
| 5 | Purchasing & Import | quotation comparison, POs, three-way match, landed cost | §3.3, §3.2 |
| 6 | Job Orders | outsourced production, custody, service invoices, author rights | all kernel |
| 7 | Warehouses | 3 locations, transfers, counts, 4-state stock, ATP | §3.2 |
| 8 | Sales | quotes, proforma, final invoices, outbound packing lists | all kernel |
| 9 | Collections | aging, task lists, promise-to-pay, auto-block | §3.3 |
| 10 | Freight & Containers | booking, consolidation, stowage, dual-track docs, lock | all kernel |
| 11 | Exhibitions | temporary entity, custody, offline POS, settlement | §3.2, §3.3 |
| 12 | Finance | statements, opening balances, corrections, activity tags | §3.3 |
| 13 | Customer Portal `NOT IN SCOPE` | invoices, statement, cartons awaiting shipment, container tracking, order requests — **specified, not built** | §3.3, §3.4 |
| 14 | Reporting | profitability per container, activity separation, demand | all |
| 15 | Admin & RBAC | roles, audit log, glossary, backups | all |
| 16 | WhatsApp AI agent | proactive customer notifications + inbound Q&A under a permission scope | §3.3, §3.4 |
| 17 | In-system assistant | natural-language search over system data; free-text order entry in POS | all |

Subsections below cover only the modules that need detail beyond the table. A module without a subsection is fully described by its row, the kernel (§3), the engines (§4) and the workflow that drives it (§9).

### 7.4 BOM & Planning

```sql
CREATE TABLE bom_lines (
  id              bigserial PRIMARY KEY,
  edition_id      bigint NOT NULL REFERENCES editions(id),
  component_edition_id bigint NOT NULL REFERENCES editions(id),  -- raw material
  qty_per_copy    numeric(18,6) NOT NULL,
  uom             text NOT NULL
);

CREATE TABLE supply_lead_times (
  id            bigserial PRIMARY KEY,
  party_id      bigint NOT NULL REFERENCES parties(id),
  edition_id    bigint NOT NULL REFERENCES editions(id),
  ordered_at    date NOT NULL,
  arrived_at    date,
  lead_days     int GENERATED ALWAYS AS (arrived_at - ordered_at) STORED
);
```

Reorder point applies in **two directions**: raw material *and* finished goods. Minimum stock uses a safety margin covering the **longest** lead time actually recorded, not the average. Record `ordered_at` and `arrived_at` from day one to build that history.

**Aggregate shared material requirements across job orders before purchasing** — otherwise the company loses bulk pricing and repeats POs for the same item.

### 7.5 Purchasing & Import

```sql
CREATE TABLE supplier_quotations (
  id           bigserial PRIMARY KEY,
  party_id     bigint NOT NULL REFERENCES parties(id),
  edition_id   bigint NOT NULL REFERENCES editions(id),
  price        numeric(18,4) NOT NULL,
  currency     text NOT NULL,
  qty          numeric(18,4),
  lead_days    int,
  payment_terms text,
  received_at  date NOT NULL,
  chosen       boolean NOT NULL DEFAULT false,
  choice_reason text,   -- required when chosen and not the cheapest
  po_id        bigint REFERENCES purchase_orders(id)
);

CREATE TABLE purchase_orders (
  id            bigserial PRIMARY KEY,
  po_number     text UNIQUE NOT NULL,
  party_id      bigint NOT NULL REFERENCES parties(id),
  currency      text NOT NULL,
  expected_date date,
  payment_terms text,
  status        text NOT NULL DEFAULT 'draft',
  -- draft | approved | awaiting_delivery | partially_received | closed
  approved_by   bigint REFERENCES users(id),
  created_by    bigint NOT NULL REFERENCES users(id),
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE import_shipments (
  id               bigserial PRIMARY KEY,
  po_id            bigint REFERENCES purchase_orders(id),
  container_no     text,
  bl_number        text,
  vessel           text,
  hs_code          text,
  gross_weight_kg  numeric(18,4),
  allocation_basis text NOT NULL,   -- by_value | by_weight | by_volume
  customs_fx_rate  numeric(18,6),
  status           text NOT NULL DEFAULT 'open'
);

CREATE TABLE import_cost_lines (
  id          bigserial PRIMARY KEY,
  shipment_id bigint NOT NULL REFERENCES import_shipments(id),
  line_type   text NOT NULL,
  -- goods_value | ocean_freight | customs_duty | clearance_fees
  -- | inland_transport | port_charges | demurrage
  amount      numeric(18,4) NOT NULL,
  currency    text NOT NULL,
  fx_rate     numeric(18,6) NOT NULL,
  is_late     boolean NOT NULL DEFAULT false,  -- arrived after shipment close
  created_at  timestamptz NOT NULL DEFAULT now()
);
```

**Approved decisions:** materials are both local and imported with different document paths; **no fixed supplier** — quotations are compared every time; purchase currency EGP and USD; **import costs load onto material cost**, never a separate expense; receipt may be full or partial; **a purchase order is mandatory before any receipt, no exception**.

**Quotation comparison is a formal recorded step inside the system.** Record a reason whenever the chosen quote is not the cheapest — it protects the decision under later review and builds a market price history.

**Three-way match** is required: supplier invoice vs PO vs actual receipt. No invoice is approved before the three agree, or the variance is explicitly approved by a manager.

**Landed cost:** all `import_cost_lines` distribute over quantities actually received, producing the final unit cost that feeds the weighted average. The allocation basis across multiple items in one shipment is **configurable per shipment**, fixed when the shipment is created.

**Late costs after close** (demurrage, penalties, fee differences) post as an additional cost line with `is_late = true`. The system recalculates unit cost and updates the weighted average **from the registration date forward**, without touching the original approved documents.

**Real import reference:** 610 rolls of PVC coated paper from a Turkish supplier, container TRPU2175900, 20,270 kg gross, HS 4811900010, USD 24,490 at FX 52.0174, terms CY/CY.

### 7.6 Job Orders & author rights

```sql
CREATE TABLE job_orders (
  id            bigserial PRIMARY KEY,
  jo_number     text UNIQUE NOT NULL,
  vendor_party_id bigint REFERENCES parties(id),   -- print vendor
  print_year    int,                               -- NOT on the edition
  planned_qty   numeric(18,4) NOT NULL,
  received_qty  numeric(18,4) NOT NULL DEFAULT 0,
  stage         text NOT NULL DEFAULT 'open',
  -- open | in_printing | in_binding | awaiting_final_receipt | closed
  expected_date date,
  estimated_total numeric(18,4),
  actual_total  numeric(18,4),
  parent_job_order_id bigint REFERENCES job_orders(id),  -- reprint-due-to-error
  created_by    bigint NOT NULL REFERENCES users(id),
  created_at    timestamptz NOT NULL DEFAULT now()
);

-- A job order may cover MULTIPLE titles for one print vendor
CREATE TABLE job_order_lines (
  id           bigserial PRIMARY KEY,
  job_order_id bigint NOT NULL REFERENCES job_orders(id),
  edition_id   bigint NOT NULL REFERENCES editions(id),
  planned_qty  numeric(18,4) NOT NULL,
  received_qty numeric(18,4) NOT NULL DEFAULT 0
);

CREATE TABLE title_contracts (
  id            bigserial PRIMARY KEY,
  title_id      bigint NOT NULL REFERENCES titles(id),
  counterparty_party_id bigint NOT NULL REFERENCES parties(id),  -- author
  lump_sum      numeric(18,4),
  free_copies   int NOT NULL DEFAULT 0,     -- typically 25
  signed_date   date NOT NULL,
  term_years    int NOT NULL DEFAULT 5,
  expiry_date   date GENERATED ALWAYS AS (signed_date + (term_years || ' years')::interval) STORED
);
```

**Author contracts** (عقد طباعة ونشر وتوزيع) are real and absent from every requirement document. Terms observed: a lump-sum buyout declared as full satisfaction of the author's financial rights, **25 free copies**, **five-year term**.

Effects: a one-time payable per title; the free copies as a **revenue-free stock issue** (`movement_type = 'author_copies'`); and **rights expiry gates reprint authorisation** — warn when a reprint job order is opened on a title whose contract expires before expected delivery.

**Service invoices are multi-unit.** Each line carries its own unit: per ream, per thousand, per copy, or a fixed amount. Do not force one unit per invoice.

**Reprint due to error** posts to the **original** job order via `parent_job_order_id`, so the title's true cost reflects it.

### 7.10 Freight & Containers

```sql
CREATE TABLE containers (
  id            bigserial PRIMARY KEY,
  container_no  text,                  -- PCIU 010156/2
  size          text NOT NULL,         -- 20ft | 40ft
  agent_party_id bigint REFERENCES parties(id),   -- THE financial counterparty
  destination_country text,
  pol           text,                  -- Alexandria | Sokhna
  pod           text,                  -- Mogadishu | Tin Can Island | …
  vessel        text,
  shipping_line_party_id bigint REFERENCES parties(id),
  booking_no    text,
  bl_number     text,
  sailing_date  date,
  contents_confirmed_by bigint REFERENCES users(id),  -- storekeeper, Rule 1
  contents_confirmed_at timestamptz,
  status        text NOT NULL DEFAULT 'planning',
  -- planning | contents_confirmed | booked | loading | shipped | closed
  bl_extra      jsonb,   -- per-market fields: Form M / BA / DCN for Nigeria
  tracking_url  text,            -- shipping line's tracking page
  last_position text,            -- last known position, cached
  position_updated_at timestamptz
);

-- Why packages sit unshipped. The client asked for this explicitly.
CREATE TABLE package_holds (
  id                bigserial PRIMARY KEY,
  container_line_id bigint REFERENCES container_lines(id),
  reason_code       text NOT NULL,
  -- undeclared_contents | prohibited_item | missing_approval
  -- | awaiting_space | customer_instruction | other
  internal_note     text NOT NULL,
  visible_to_customer boolean NOT NULL DEFAULT false,
  created_by        bigint NOT NULL REFERENCES users(id),
  created_at        timestamptz NOT NULL DEFAULT now(),
  resolved_at       timestamptz
);

CREATE TABLE container_lines (
  id            bigserial PRIMARY KEY,
  container_id  bigint NOT NULL REFERENCES containers(id),
  ownership     text NOT NULL,         -- company_owned | customer_custody
  customer_party_id bigint REFERENCES parties(id),   -- the END customer
  sending_library_party_id bigint REFERENCES parties(id),  -- operational only
  edition_id    bigint REFERENCES editions(id),
  package_from  int, package_to int,
  units_per_package int,               -- عبوة: per packing-list line, NOT a product attribute
  package_count int,
  total_units   numeric(18,4),
  unit_price    numeric(18,4),
  freight_included boolean NOT NULL DEFAULT false,   -- "شامل الشحن"
  loaded_qty    numeric(18,4) NOT NULL DEFAULT 0,
  stowage_seq   int
);

CREATE TABLE container_cost_lines (
  id           bigserial PRIMARY KEY,
  container_id bigint NOT NULL REFERENCES containers(id),
  bucket       text NOT NULL,
  -- ocean_freight | clearance_and_inland | warehouse_and_labour | commission
  line_type    text NOT NULL,   -- the real ~9 line items, see below
  amount       numeric(18,4) NOT NULL,
  currency     text NOT NULL,   -- EGP | USD | EUR
  fx_rate      numeric(18,6) NOT NULL,
  amount_base  numeric(18,4) NOT NULL,
  is_late      boolean NOT NULL DEFAULT false
);
```

**Four buckets are a reporting grouping over roughly nine real line items.** An actual clearing-agent statement for one 20ft container, Sokhna → Somalia, totalled **EGP 38,501**:

| Line | EGP |
|---|---|
| إيصال سداد مطالبة | 1,232 |
| رسوم ميناء كراكة وطريق | 3,769 |
| صالح/كراكة 20 قدم | 500 |
| الأمن الجمركي ومكافحة | 1,000 |
| مصاريف فتح شهادة | 1,000 |
| مصاريف اللجنة | 7,000 |
| استخراج نموذج 13 | 800 |
| **نولون (ocean freight)** | **22,500** |
| أتعاب | 700 |

Model cost lines as a **typed table mapped to buckets** — do not hard-code four columns.

**Currencies:** ocean freight is invoiced in **EUR** by some lines (Marina Shipping / Grimaldi: EUR 2,338 for a 20DV Alexandria → Tin Can Island), in USD and EGP by others.

**HS code varies by shipment and market:** 4901899000 on a Nigeria B/L, 4901900010 on a Somalia export declaration, 4811900010 on a paper import. Store per shipment line, not as a company constant.

**Market-specific B/L fields:** Nigeria requires Form M number, BA number and DCN number. Use `bl_extra jsonb` keyed by destination.

**Ports:** Alexandria and Sokhna both in use. **Land freight to Libya exists** alongside sea — a road delivery note records driver name and passport number, matching the `السائق` column in their warehouse journal.

**Container tracking.** Al-Quds books **directly with the shipping line** and holds its own account on the line's website — no forwarder in between. Where the line exposes tracking, store `tracking_url` and surface the last known position in the customer portal. Lines with no usable tracking fall back to a manually updated `last_position`. Do not promise automatic tracking for every line.

**Hold reasons.** Packages frequently sit unshipped and the client needs the reason recorded. Causes seen in practice: a sending bookshop includes undeclared non-book items (سواك, prayer beads), an approval is missing, or there is no container space. Al-Quds accepts shipments **as declared** under the liability undertaking (§11.2) and does not open cartons, so the reason is often discovered late.

`visible_to_customer` exists because the client said plainly that sometimes the real reason cannot be given to the customer until the goods have moved. Default false; the internal note is always recorded regardless.

**Commission** is a flat amount, identical for all customers.

**Demurrage and detention** arrive after the invoice. Since approved documents are immutable, they post as a **separate claim line** (`is_late = true`), charged to the customer.

### 7.11 Exhibitions

```sql
CREATE TABLE exhibitions (
  id          bigserial PRIMARY KEY,
  name        text NOT NULL,          -- Algeria | Kuwait | Baghdad …
  country     text,
  start_date  date, end_date date,
  responsible_user_id bigint REFERENCES users(id),
  price_list_id bigint,
  cash_account_id bigint REFERENCES cash_accounts(id),  -- may be temporary/foreign
  export_mode text NOT NULL DEFAULT 'temporary',        -- temporary | final
  status      text NOT NULL DEFAULT 'planning'
);

CREATE TABLE exhibition_authorisations (
  id            bigserial PRIMARY KEY,
  exhibition_id bigint NOT NULL REFERENCES exhibitions(id),
  authorising_party_id bigint NOT NULL REFERENCES parties(id),  -- other publisher
  document_id   bigint REFERENCES documents(id),
  note          text
);
```

Two facts the requirement documents omit, both evidenced in real paperwork: exhibitions carry **other publishers' titles under written authorisation** (a تفويض naming the authorising publisher, the exhibiting party and the fair), and some fairs (Algeria) require non-resident exhibitors to **open a temporary local bank account** — so fair revenue can flow outside the company's main accounts.

**Temporary export with return guarantee** is the customs mode (their decision). Settlement equation is enforced: `sent = sold + returned + documented_damage`.

**A bookshop that holds its own stock is modelled as a point of sale, not a warehouse** (client decision, 9 Oct). It is still stock-counted, but flagged as a POS location.

### 7.13 Customer Portal

`NOT IN SCOPE` **— removed from the current offer on 13 October. Do not build it yet.**

It is specified here in full because the client wants it, it was agreed in the 9 October meeting, and it is likely to return. More importantly, **`portalScope` below is still required now** — the WhatsApp agent (§7.16) reads through it, so the scope must be defined and enforced even while the portal UI is not built.

A separate web surface, **in Arabic**, for the end customer.

**What the customer sees:**

| Shows | Detail |
|---|---|
| Invoices | All their invoices, paid and outstanding |
| Account statement | Current balance, payments made, what remains |
| Cartons awaiting shipment | How many of their packages sit in the warehouse not yet shipped |
| Container position | Last known location, where the shipping line exposes it (§7.10) |
| Order request | Free-text or picked from a title list; lands in the system for approval |
| Online payment | For local customers, where a gateway is connected |

**The hard constraint — the client was explicit:**

> **The portal must never show stock quantities.** The customer may browse **titles only, with no balances**. Al-Quds decides case by case whether an item is available and tells the customer directly.

Enforce this in the query layer, not the UI. The portal's product endpoint returns `title`, `author`, `sku` and nothing resembling a quantity, a reservation, a location or an availability flag. A portal order request records **what the customer asked for**, never whether it can be fulfilled.

**Why it exists, in the client's words:** customers phone to ask what their balance is; he has to be in the office, ask someone to photograph a statement and send it. The portal removes that loop.

**Branding:** the company palette is **navy (كحلي)** and **maroon (نبيتي)** — their logo colours. Arabic UI, RTL, per §13.

### 7.16 WhatsApp AI agent

**In scope.** Billed to the client monthly at the cost of the model subscription plus usage-based hosting, with no fixed fee (commercial decision, 13 October). It is **not** the in-system assistant — they are two different things and the client distinguishes them.

Because billing is pass-through on usage, **log token and message consumption per party per month** from day one. There is no margin absorbing an overrun.

**Outbound — proactive.** The agent messages customers on WhatsApp when something happens in the system: shipment received, expected sailing date, container shipped, payment due, promise-to-pay falling due, documents pending. Driven by the same `notification_events` engine (§4.4) with `channel = 'whatsapp'` — do not build a second notification path.

**Inbound — answering.** A customer can ask it anything and it answers from the system under a defined permission scope, 24/7.

```ts
// The agent's read scope is the SAME as the portal's. This is not optional.
// Define portalScope even though the portal UI is NOT being built (§7.13).
agentScope = portalScope   // invoices, statement, own packages, own container position
// NEVER: stock quantities, other customers' data, cost, margin, internal notes
```

> The client stated the no-stock-quantity rule for the portal. Applying it to the agent is an inference, but a necessary one — an agent with a wider scope than the portal becomes a side channel around the rule. Flag it to the client for confirmation rather than quietly widening it.

**Volume:** the client's own estimate is that ~1,000 messages/day would be far more than needed; real traffic follows customer-facing movements. Build a per-party daily cap and a global cap anyway.

**Language:** Arabic primary, with French and English templates for the African customer base.

Every outbound message and inbound exchange is logged against the party and is visible to staff — the agent is an employee, not a black box.

### 7.17 In-system assistant

Already in scope, distinct from §7.16. Natural-language query over system data for **staff**: "find me title X", "what went to Somalia last month". Returns what the user's role is allowed to see (§10.2) — the assistant is not a permission bypass.

**One concrete use the client asked for:** in the POS, the seller types an order in free text the way they would say it — *"واحد كارتونة عنوان كذا، اتنين كارتونة عنوان كذا"* — and the assistant parses it into structured order lines for confirmation. The seller never has to learn a form.

---
## 8. Stock states, reservations & available-to-promise

The highest-traffic logic in the system. Get it wrong and every other number is wrong.

### 8.1 Four states, not two

The original design had only *soft reservation* and *physical pick*, both describing stock already in a warehouse. The missing state is quantity that **does not exist yet** but is committed to an open job order with an expected arrival date. Without it, sales can only see what has arrived and must refuse an order for an item currently being manufactured.

| State | Meaning | Physically present? |
|---|---|---|
| `available` | Free in one of the 3 warehouses | ✅ |
| `soft` | Logically allocated to a customer order; reallocatable under rules | ✅ |
| `picked` | Shipment date confirmed; untouchable | ✅ |
| `incoming` | Not yet received; tied to an open job order with an expected date | ❌ |

`incoming` carries a sub-state mirroring the job order stage — `in_printing` → `in_binding` → `awaiting_final_receipt` — so the date shown to the customer matches production reality.

```sql
CREATE TABLE reservations (
  id            bigserial PRIMARY KEY,
  order_id      bigint REFERENCES sales_orders(id),
  container_id  bigint REFERENCES containers(id),   -- third-party custody allocation
  edition_id    bigint NOT NULL REFERENCES editions(id),
  party_id      bigint NOT NULL REFERENCES parties(id),
  qty           numeric(18,4) NOT NULL,
  qty_shipped   numeric(18,4) NOT NULL DEFAULT 0,

  source        text NOT NULL,       -- stock | job_order
  job_order_id  bigint REFERENCES job_orders(id),

  state         text NOT NULL,
  -- soft | picked | incoming | awaiting_supply | closed | cancelled
  sub_state     text,                -- in_printing | in_binding | awaiting_final_receipt
  expected_date date,                -- promised to the customer; from the job order
  priority_ratio numeric(8,4),       -- paid_to_date / order_value, see §8.4

  pulled_count  int NOT NULL DEFAULT 0,   -- escalate above 2
  created_by    bigint NOT NULL REFERENCES users(id),
  created_at    timestamptz NOT NULL DEFAULT now(),

  CHECK ((source = 'job_order') = (job_order_id IS NOT NULL)),
  CHECK (qty_shipped <= qty),
  CHECK (order_id IS NOT NULL OR container_id IS NOT NULL)
);
```

### 8.2 The three queries sales screens use

```sql
-- physically free right now
free_now = stock_balance(edition, location, 'company_owned')
         - SUM(qty - qty_shipped) WHERE state IN ('soft','picked')

-- committed but not yet arrived
incoming = SUM(job_order_lines.planned_qty - job_order_lines.received_qty)
           FOR open job orders covering this edition

-- available to promise by a given date
atp(date) = free_now
          + SUM(incoming from job orders with expected_date <= date)
          - SUM(reservations already made against those job orders)
```

`atp(date)` is what the sales screen shows. **When `free_now = 0` but `atp` is positive, the screen offers a delivery date instead of refusing the order.**

### 8.3 Hard constraints

- **A reservation moves no stock (I9).** The `stock_movements` row is written at container door scan or goods issue, for the quantity **actually loaded**.
- **Total reservations against a job order may never exceed its planned quantity.** Enforce in the database, not the service layer — the system must not promise quantity that was never going to be produced.
- **Deduction happens at physical loading only.** Any remainder returns to `available` and the reservation stays open for that balance.

This applies to **both** own goods and third-party packages. Third-party packages (`ownership = 'customer_custody'`) allocate to a **container** rather than a sales order (`container_id` set, `order_id` null); unloaded packages stay in custody flagged `awaiting_next_container`.

> Their own paperwork already does this: the التيسير container manifest carries a column **"متبقى بالمخزن من الحاوية السابقة"** — remainder in the warehouse from the previous container.

### 8.4 Shortfall priority (approved decision 3)

When a job order's received quantity falls short and several customers hold reservations against it:

```
ORDER BY priority_ratio DESC,          -- paid_to_date / order_value
         created_at ASC                -- tie-break: earliest reservation
```

**Ratio, not absolute amount** — so a large order with a small deposit gains no undeserved priority. The ranking is computed automatically and shown to the responsible user when the shortfall occurs. A manager may override; the override is recorded in the audit log. Unfulfilled customers move to `awaiting_supply` and are notified of the new expected date.

> Client's own note: this incentivises larger deposits, which helps liquidity, but customers must be told the rule in the terms of dealing so it does not look arbitrary at the first real shortfall.

### 8.5 Reallocation

An urgent customer may pull from another customer's `soft` reservation — **never** from `picked`, and **never** from a fully-paid customer's reservation without explicit manager approval. The original reservation moves to `awaiting_supply`, is auto-reassigned when stock arrives, and the customer is notified automatically. Escalate to senior review if the same order is pulled more than twice.

### 8.6 Delay propagation & demand signal

**Job order delay propagation:** when a job order's expected completion date changes — routine when an outsourced printer or binder slips — every customer holding a reservation against it is notified of the new date automatically. Do not let the original promised date stand until the customer discovers it themselves.

**Repeated-demand signal:** when `N` distinct customers request an edition in `incoming` state (default `N = 3`, configurable), raise `repeated_demand_on_pending_item` to management. It means the planned production quantity is probably too low, and it is only actionable **before the job order closes**.

---

## 9. Workflows

### 9.1 Sale from company stock

```
order_received → credit_check → quotation → order_approved
  → availability_check
      ├─ free_now sufficient  → soft reservation
      ├─ atp(date) sufficient → reservation source='job_order' + promised date
      └─ neither              → job order (9.4)
  → proforma_invoice + draft packing list     [editable until shipment]
  → shipment_date_confirmed → physical_pick   [no longer editable]
  → outbound packing list (quantity match enforced)
  → barcode labels → container (9.3)
  → final_invoice  [pulls container no, shipping line, route automatically]
```

Credit check blocks progression on an exceeded limit; only a manager override unblocks it, recorded with name and timestamp. All discounts are centrally approved (ceiling 10% — `OPEN`: of invoice value or unit price). No multi-level quote approval.

**Special case — raw material sale:** paper selling price differs from manufacturing cost, entered manually per transaction, deducted from the same production stock balance.

**Two sales channels:** bookshop channel (local, weekly collection rounds with a visit sheet) and administration channel (export containers, quote → proforma → final after shipment).

### 9.2 Third-party goods shipping

```
shipping_request
  → 6 mandatory documents uploaded          [BLOCKING, see §11.2]
  → supplier portal links sent (WhatsApp)
  → packing lists received                  [portal primary; OCR assisted + human review]
  → storekeeper confirms container contents complete   ← THE ONLY GATE that opens booking
  → booking request sent → booking confirmed → customer approval
  → per-package barcode on arrival
      [goods receipt permit BLOCKED until packing list + export authorisation uploaded]
  → master packing list: renumber 1..N across all libraries   [see §9.9]
  → container (9.3)
```

If an official approval (Al-Azhar, Publications Authority) is suspended: **continue remaining steps with a persistent alert**; do not halt the whole shipment.

**Inbound packing-list reality, from real specimens:**
- Senders include **foreign publishers** (one from Damascus, Syria), not only Egyptian bookshops.
- One package routinely contains **many titles**.
- Some senders already print **EAN-13 barcodes** per line — capture them rather than minting new codes.
- The same column header `الاجمالى` means **money** on one list and **units** on another. Never assume a fixed meaning.
- The worst real specimen is a **hand-filled, badly photocopied pre-printed form with annotations in pen**, three rows completed. This is why the supplier portal is the primary path and OCR is assisted entry with mandatory human review — never automatic extraction.

### 9.3 Container — where 9.1 and 9.2 converge

Accepts lines from both flows, distinguished by `ownership`.

```
stowage_plan_generated      [ordered by library and package sequence]
  → door_scan               [offline-capable; audible alert on sequence violation]
  → cost lines collected + flat commission
  → [demurrage → separate claim line, never an invoice edit]
  → FX conversion → freight_claim issued
  → document split:  customs_track  |  actual_track
        customs invoice approved by shipping_officer AFTER manager approval;
        approver + timestamp recorded per instance
  → partial shipment allowed → remaining packages 'awaiting_next_container'
  → FINANCIAL LOCK:
        agent balance > 0  → watermarked DRAFT B/L only
        agent balance = 0  → release the full final document set
```

**The financial lock reads the AGENT's balance** (approved decision 1), not per-library balances. Sending-library data is operational only.

### 9.4 Outsourced manufacturing

```
job_order_opened            [may cover MULTIPLE titles for one print vendor]
  → requirement from BOM + waste% by paper type
  → shortfall → purchase (9.5)
  → custody_out to printer  [manager approval; ownership stays company_owned]
  → print service invoice lines added progressively (per ream / per thousand / fixed)
  → custody transfer printer → binder
  → binding invoice (per copy)
  → final receipt            [partial receipts supported, completion % updated]
  → expected vs received compared → discrepancy if beyond waste%
  → reprint-due-to-error cost posts to the ORIGINAL job order
  → reservations with source='job_order' fulfilled on receipt
        [shortfall allocated by §8.4]
  → job total accumulated → order closed
  → estimated vs actual compared → alert beyond threshold
```

**Before opening a reprint job order, check `title_contracts.expiry_date`** — warn if publishing rights expire before expected delivery.

### 9.5 Purchase & import

```
reorder_point_hit → alert    [snoozeable / schedulable, NOT auto-PO]
  → shared material requirements aggregated across job orders
  → supplier quotations recorded and compared   [reason required if not cheapest]
  → PO issued (manager approval) + ordered_at recorded for lead-time history
  → [import: ocean freight + customs duty + clearance + inland transport
            → landed on material cost, basis fixed per shipment]
  → physical receipt + arrived_at recorded → weighted average recalculated
  → three-way match: PO vs receipt vs supplier invoice
  → supplier ledger debit + supply document + purchase invoice
  → installment schedule with proactive due-date alerts
  → [late costs → additional line, recalc forward, originals untouched]
```

### 9.6 Exhibitions

```
exhibition_created          [temporary entity, own price list, own cash account]
  → authorisations recorded for any third-party publishers' titles
  → goods out as exhibition custody    [TEMPORARY EXPORT with return guarantee]
  → live POS sales (offline-capable) deducted from CUSTODY balance, not warehouse
  → settlement:  sent = sold + returned + documented_damage    [ENFORCED]
  → returns enter via a distinct 'exhibition_return' movement
  → settlement invoice for actual sales only
```

### 9.7 Collections

```
daily aging classification → task lists → contact outcome recorded
  → promise_to_pay (dated) → immediate escalation if the date passes unpaid
  → auto-block on new orders past threshold → only a manager unblocks
  → reads the SAME balance query the shipping lock reads
```

Aging buckets configurable (currently 1–7, 8–30, 30+ days). Each customer or region has an assigned collections officer.

### 9.8 Daily tracking (المتابعة اليومية)

Three levels, **not one record**. The wider the time range, the less detail:

| Level | Purpose | Shape |
|---|---|---|
| Daily | Confirm the day was recorded correctly | Full detailed movement log |
| Weekly | Follow the general trend | Aggregated by movement type and party |
| Monthly | Decide | One page, limited indicators and variances only |

**Management by exception:** the default screen shows only what needs a decision — unexplained transfer variance, job order past its expected date, item below minimum, goods sitting with a service vendor longer than usual. Normal movements remain viewable but do not lead the screen.

**The warehouse journal is never written by hand.** It is generated automatically from the operations themselves: supplier receipt from purchasing, finished goods from job-order receipt, inbound library goods from the receiving packing list, and so on.

### 9.9 Packing list capture and dual-track derivation

**The client named this as his single biggest worry.** Today an employee holds the paper list and retypes every line into Excel, colour-codes by bookshop, renumbers, then builds a second filtered list by hand — deleting rows and re-saving. Files get overwritten and lost. If the system does not solve this, the employee goes straight back to Excel and the project has bought nothing.

**The requirement is therefore specific: nobody ever retypes a packing list.** Capture, correct, confirm.

#### The two lists and why they differ

| List | Goes to | Content |
|---|---|---|
| Customer packing list | The end customer | The true contents |
| Customs packing list (كشف جمركي) | Al-Azhar / Publications Authority / customs | The same list with certain titles **removed** |

Titles are removed because an approval is unavailable or because the destination country bans that author or title — not a religious prohibition, a regulatory one that varies by market.

The customs list is **derived from** the real list. Never captured twice.

#### The flow

```
capture        → upload the list as it arrived: phone photo, WhatsApp image,
                 scan of a printed sheet, Excel file, or portal entry
  ↓
parse          → assisted extraction produces draft lines with a confidence score
  ↓
REVIEW SCREEN  → user corrects errors inline, field by field
                 [the user NEVER starts from a blank form]
  ↓
confirm        → generates the CUSTOMER packing list  (document, immutable once approved)
  ↓
FILTER STEP    → user ticks the lines to exclude, each with a reason code
  ↓
confirm        → generates the CUSTOMS packing list   (document, immutable once approved)
```

Both documents link back to the same capture. The exclusion set, its reasons, and who approved it are recorded permanently.

```sql
CREATE TABLE packing_list_captures (
  id            bigserial PRIMARY KEY,
  container_id  bigint REFERENCES containers(id),
  sending_library_party_id bigint REFERENCES parties(id),
  source_kind   text NOT NULL,
  -- phone_photo | whatsapp_image | printed_scan | excel | portal | manual
  storage_key   text,
  parsed_at     timestamptz,
  parse_confidence numeric(5,2),
  reviewed_by   bigint REFERENCES users(id),
  reviewed_at   timestamptz,
  customer_doc_id bigint REFERENCES documents(id),
  customs_doc_id  bigint REFERENCES documents(id),
  status        text NOT NULL DEFAULT 'captured'
  -- captured | parsed | reviewed | customer_issued | customs_issued
);

CREATE TABLE packing_list_lines (
  id            bigserial PRIMARY KEY,
  capture_id    bigint NOT NULL REFERENCES packing_list_captures(id),
  line_no       int NOT NULL,
  package_from  int, package_to int,
  units_per_package int,          -- عبوة, per line, NOT a product attribute
  title_text    text NOT NULL,    -- as written on the source
  author_text   text,
  qty           numeric(18,4),
  edition_id    bigint REFERENCES editions(id),   -- matched where possible, nullable
  ean13         text,

  -- mandatory for the approval bodies
  page_count       int,
  paper_type       text,
  publication_year int,

  included_in_customs boolean NOT NULL DEFAULT true,
  exclusion_reason text,
  -- azhar_approval | publications_authority | destination_ban | other
  edited_by_user   boolean NOT NULL DEFAULT false   -- did the reviewer change the parse?
);
```

**Mandatory approval columns.** The approval bodies require **page count, paper type and publication year** on every line. Block issuing the customs list while any included line is missing one of the three.

**Parse quality is a product decision, not a technical one.** The portal (§9.2) is the primary path because a portal entry needs no parsing at all. OCR is the fallback for the real-world inputs — a handwritten form, badly photocopied, annotated in pen, is in the supplied evidence. Never present extraction as automatic; the review screen is mandatory and `edited_by_user` tracks how often the parse is wrong so the client can see whether OCR is earning its place.

**Master consolidation.** Every bookshop numbers its own packages from 1. The master list merges all of them for one container and renumbers **1..N** across the whole load (§9.2). The original per-bookshop numbering is retained on the line for traceability.

---

## 10. Enforced rules & RBAC

### 10.1 Enforced rules — server-side guards

Client-side checks are UX only.

| Rule | Behaviour |
|---|---|
| Goods before booking | Block the booking request until the storekeeper confirms container contents complete |
| Documents before receipt permit | Block goods-receipt-permit print until packing list + export authorisation are uploaded |
| Six mandatory documents | Block progression of a third-party shipment until all six are present |
| Financial lock | Block B/L and real invoice while the **agent's** balance > 0; watermarked draft only |
| Credit limit | Block new order on exceeded limit except manager override, recorded |
| Packing list match | Block approval if any item total differs from the order quantity |
| Missing rate | Block save with an alert until the price is entered manually |
| Count variance | Auto-accept below 0.5%; manager approval above |
| Approved document | Never editable; corrections are new rows |
| Balances | Never stored; always computed |
| External custody | Block material leaving as custody without prior approval |
| Reservation ceiling | Block reservations exceeding a job order's planned quantity |
| Self-approval | Block when `requested_by = decided_by` |
| Cost visibility | Prices, cost and profit enforced at query level, not just UI |
| Rights expiry | Warn when a reprint is opened on a title whose contract expires before delivery |
| **Portal stock blindness** | The customer portal and the WhatsApp agent must never return a stock quantity, reservation, location or availability flag. Enforce in the query layer. |
| **Customs list completeness** | Block issuing the customs packing list while any included line lacks page count, paper type or publication year |
| **No retyping** | The packing-list review screen must be pre-filled from the capture; there is no blank-form entry path for an inbound list |
| Audit | Every mutation attributed, no exceptions |

### 10.2 Roles — ten

```ts
type Role =
  | 'manager'             // مدير — 3 users, IDENTICAL rights, ONE role
  | 'accountant'          // محاسب
  | 'sales_officer'       // مسؤول مبيعات
  | 'collections_officer' // مسؤول تحصيل
  | 'shipping_officer'    // مسؤول شحن
  | 'storekeeper'         // أمين مخزن
  | 'purchasing_officer'  // مسؤول مشتريات
  | 'production_officer'  // مسؤول إنتاج
  | 'exhibitions_officer' // مسؤول معارض
  | 'system_admin'        // مدير النظام — INSHARO support only (ENGINEERING)
```

**`system_admin` is INSHARO's support role, not a business role** (`ENGINEERING`, 15 Oct). It administers users, roles, settings, the audit log and backups — nothing else. It sees **no business data** (no prices, cost, profit, balances or party files), approves **nothing**, and there is **no `Gate::before` bypass** for it or for anyone: every permission is granted explicitly. Only a `system_admin` may grant `system_admin`; only a `manager` may grant `manager`.

**Multi-role users are supported.** One person may hold several roles (storekeeper + shipping officer); effective permissions are the **union**. Never invent a composite role per combination.

**Four permission levels per screen:** `none` (not visible at all) · `view` · `edit` (create/modify before approval) · `approve` (finalises and locks the document).

**Three managers hold exactly identical rights under one role** (approved decision 4). The audit log records *which* manager approved each operation, so individual accountability survives.

### 10.3 The thirteen manager-only approvals

No role other than `manager` may approve these:

1. Selling below cost
2. Any manual change to the proposed selling price
3. Approving a new selling price after a cost change
4. Granting a special discount (on a product or an invoice total)
5. Exceeding the credit limit / conditional release
6. Removing a block on a late customer
7. Approving a purchase order before sending it to the supplier
8. Approving a variance between purchase order, receipt and invoice
9. Settling stock-count variances
10. Closing an unexplained variance in an inter-warehouse transfer
11. Releasing material as external custody to a service vendor
12. Pulling from the reservation of a fully-paid customer
13. Settling an exchange difference or correction line in the ledger

### 10.4 Capability matrix

| Capability | manager | accountant | sales | collections | shipping | store | purch | prod | exhib | sysadm |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| View prices / cost / profit | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Approve any of the 13 | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Request discount | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Record customer payments | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| View party balance (numeric) | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| View customer *status* only | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Quotations / sales orders | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Collection task lists | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Container booking | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Issue shipment documents | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Approve customs invoice | ✅ (first) | ❌ | ❌ | ❌ | ✅ (after) | ❌ | ❌ | ❌ | ❌ | ❌ |
| Goods receipt / issue / loading | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Confirm container contents | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Stock counts / transfers | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Supplier quotations / create PO | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ |
| Job orders / record actual waste | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Exhibition custody / POS / settle | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| View passport / national-ID files | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Manage users and roles | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Company settings | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| View audit log | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Run and restore backups | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

`sysadm` is `system_admin` (§10.2): INSHARO support only — it never gains a business capability, and no role passes a check it was not granted.

**Shipping officer sees whether a customer is clear or defaulting, never the balance amount** — the financial lock returns a boolean to that role.

**The customer portal and the WhatsApp agent are not roles — they are a scope.** `portalScope` is defined once (§7.13) and both surfaces read through it. Widening one widens the other, which is the point: it prevents the agent becoming a side channel around the portal's stock-blindness rule.

---

## 11. Documents

### 11.1 Document types

`quotation` · `proforma_invoice` · `final_invoice` · `packing_list_inbound` · `packing_list_outbound` · `master_packing_list` · `goods_receipt_permit` · `supply_document` · `purchase_invoice` · `service_invoice` · `bill_of_lading` · `customs_invoice` · `customs_packing_list` · `certificate_of_origin` · `export_authorization` · `liability_undertaking` · `job_order` · `freight_claim` · `statement_of_account` · `collection_sheet` · `delivery_note` · `exhibition_authorisation` · `title_contract`

### 11.2 The six mandatory documents for third-party shipping

The sixth is **إقرار وتعهد** — a signed liability undertaking collected from every sending library or publisher. It records the signatory's name, role, nationality, library name and address, phone, destination library and country, and package count, with the library's stamp.

Its legal effect: Al-Quds is declared **وسيط فقط** (intermediary only) with no knowledge of package contents, and the sending library accepts full criminal and civil liability for contraband — forged books, expired or forged mushaf permits, manuscripts, antiquities, prohibited materials, atlases with disputed borders, unauthorised educational materials, or any non-Egyptian-origin product.

**It captures a national ID number**, so it needs the same access control and read-logging as passport attachments (§3.1, §4.9).

### 11.3 Document generation facts from real specimens

- **The commercial export invoice is a SINGLE LINE** — e.g. "460 CARTONS OF BOOKS", $40,000 — in English, FOB, payment TT, marked "EGYPTIAN GOODS 100%", with EDBE bank details. Detail lives in the packing list. **The system must produce both from one shipment.**
- **Company identity varies by document type:** شركة القدس للنشر والتوزيع (publishing), شركة القدس للتجارة والتصدير والاستيراد (customs/export authorisations), AL QUDS CO. FOR EXPORT & IMPORT (English commercial invoices). Templates select the right one.
  `OPEN` — the English transliteration is inconsistent on live documents (AL QUDS vs AL QUDES). Agree one.
- **Registration numbers** printed on export authorisations: commercial registration 241560, Publishers Union membership 492. Customs client code 100450482. Store at company level.
- **Export authorisation letters** are issued by Al-Quds to a **third-party shipping company** per destination country, addressed to الإدارة العامة للرقابة على الصادرات والمطبوعات.
- **Printed documents must match layouts the customs broker and shipping lines already accept.** Build templates from the supplied filled samples, not from scratch.

### 11.4 The dual document track

The customs track and the actual track carry **different values** — confirmed by the client and visible in the supplied case file. The customs invoice is approved by the shipping officer **after** manager approval, with approver and timestamp recorded per instance.

> Build it exactly as instructed, and note for the client: encoding this makes every instance permanently logged with a named approver and timestamp, which is a different exposure profile from the current paper process. Their written instruction, reviewed with their customs broker and counsel, should exist before this ships.

---
## 12. Reporting

### 12.1 Profitability — per container (approved decision 2)

Each container is an independent cost centre collecting its own revenue and direct costs.

```sql
-- container P&L
revenue      = SUM(ledger_entries.amount_base)
               WHERE container_id = $1 AND entry_type IN ('sales_invoice','freight_claim','commission')
direct_cost  = SUM(container_cost_lines.amount_base) WHERE container_id = $1
margin       = revenue - direct_cost
```

**Per-title profitability is deferred** — but every cost row already carries `job_order_id` and `edition_id`, so it can be added later without a rebuild.

### 12.2 Activity separation

Totals per `activity` (shipping vs manufacturing), with container-level detail beneath shipping.

**Do not label the output an income statement.** No double-entry ledger is kept; this is revenue, direct cost and margin per activity. If the client later wants statutory statements, the finance module must expand to full double-entry.

### 12.3 Standard reports

- Profitability per container, per customer, per job order
- Revenue / direct cost / margin per activity
- Aging report; daily and weekly collection task lists
- Fastest-moving editions; **entry frequency is a better reorder signal than single-batch size** (their own 2025 analysis: حصن المسلم arrived 11 separate times)
- Supplier performance: lead times, damage rate, price variance, on-time %
- Supplier concentration warning — top 2 vendors were 64% of 2025 units
- Seasonal demand by **both** calendars via `hijri_months` (Ramadan patterns)
- Stock valuation at weighted average, per location and ownership
- Custody aging — goods sitting with a service vendor longer than usual
- Market/title affinity: Mauritania ↔ Maliki fiqh, Kenya ↔ Arabic teaching series

All reports display in EGP at the rate recorded on each row; reporting-currency conversion applies at display time only.

---

## 13. Arabic RTL & interface

- `<html dir="rtl" lang="ar">`; Bootstrap 5.3 RTL build; logical utilities (`ms-`/`me-`), never `ml-`/`mr-` (`ENGINEERING`, see §2)
- **Brand palette: navy (كحلي) and maroon (نبيتي)** — the company's logo colours. The customer portal in particular must look like Al-Quds, not like a generic admin panel; the client's stated reason is that the portal is what customers judge the company by.
- Arabic UI. Customer-facing templates in Arabic, French and English (African customer base)
- **Do not mix bare Latin words inside Arabic sentences** in generated documents — it breaks bidi ordering. Wrap with explicit direction marks if unavoidable
- Arabic-Indic numerals for display where their documents use them; Western numerals in the database
- Printed documents must match the layouts the customs broker and shipping lines already accept — build from the supplied filled samples
- **Glossary is a live field-level feature** (tooltips), not a separate file. Their own costing document states that terminology confusion caused most of their calculation errors. Terms: ملزمة، فرخ، رزمة، رول، أمانة، حجز منطقي، سحب فعلي، كشف تعبئة استلام، كشف تعبئة تجهيز، عبوة، رواية، محقق، بروكين، سلوفان، فني، سوستة
- Scanner screens must work one-handed, offline, with large touch targets — ~500 scans per container

---

## 14. Build order

1. **`tests/invariants/`** — I1–I11 as failing tests
2. **Kernel** — parties, titles, editions, stock_movements, ledger_entries, documents, users/RBAC, audit triggers
3. **Engines** — rate master, FX, approvals, discrepancy, notifications, dual calendar, barcode, offline sync, audit
4. **Warehouses → Purchasing** — accurate stock first; the client has never done a cycle count
5. **Sales → Finance → Collections**
6. **Pricing/Costing → BOM → Job Orders**
7. **Freight & Containers** — *depends on a ledger that has run on real balances*
8. **Exhibitions, Reporting**
9. **Packing list capture & dual-track derivation (§9.9)** — depends on containers; the client's highest-anxiety feature, so demo the review screen early with one of their real handwritten lists
10. **`portalScope` + the WhatsApp agent** — the portal UI is out of scope (§7.13), but its scope definition is not: build and test the scope, then the agent on top of it
11. **ETA e-invoicing integration** — fields exist from step 2; wiring is last

**Do not build the freight financial lock before the ledger has run on real data.** It reads live balances; building it against synthetic data hides the failure modes that matter.

---

## 15. Data migration

- **Scope: one year**, from Excel files plus paper ledgers (their decision)
- Source: `يومية مخزن العبور 2025.xlsx` — 12 monthly sheets, columns وصف، نسخة، طرد، مورد، لزوم (عميل)، السائق. Mixes free-text movement lines with structured rows
- **Known data-quality problems, confirmed in the real file:**
  - 36 date cells inside the 2025 file carry the year 2024 (23 April, 10 May, 3 September) — monthly analysis must rely on sheet names, and the importer must validate year against sheet
  - Supplier and title names are free text with spelling variants that fragment the same entity (حسين عبده / حسين عبده-تجليد, هداية / هدايه المتعبد السالك)
  - Outbound is frequently recorded as package or container counts with **no title detail** — demand per title cannot be derived from the journal alone
  - The journal holds no prices, cost or sales value
- Deduplicate parties and titles **before** import; assign fixed codes
- Slow-moving accounts held in paper ledgers are digitised first, then imported
### 15.1 The stock count — plan agreed 9 October

| Decision | Value |
|---|---|
| Duration | **4 days** (the client's own estimate) |
| Timing | Immediately **before** go-live — not now |
| Trading during the count | **Does not stop.** Sales continue; movements during the count are posted as adjustments |
| Scope | All three warehouses, plus the bookshop holding its own stock (counted, flagged as a POS location) |

**Why it is scheduled late and not now:** counting months ahead then waiting means every sale, return and receipt in the gap invalidates the result. The count must land against a system that is ready to receive it.

**What this means for the build:** the opening-balance import must be able to absorb a count taken over four days while trading continues — so each counted line carries its own count timestamp, and any movement posted after that timestamp for the same item is applied on top rather than overwriting it.

```sql
CREATE TABLE stock_count_lines (
  id          bigserial PRIMARY KEY,
  count_id    bigint NOT NULL REFERENCES stock_counts(id),
  edition_id  bigint NOT NULL REFERENCES editions(id),
  location_id bigint NOT NULL REFERENCES locations(id),
  counted_qty numeric(18,4) NOT NULL,
  counted_at  timestamptz NOT NULL,     -- per line, not per count
  counted_by  bigint NOT NULL REFERENCES users(id),
  system_qty_at_count numeric(18,4),
  variance_pct numeric(8,4)
);
```

Opening balance for an item = `counted_qty` + the net of all movements for that item after `counted_at`. Freeze the item only while it is being counted, not for the whole four days.

---

## 16. Testing

Beyond the invariants:

- **Ledger property tests** — for any random sequence of entries, the statement balance equals `SUM(debit) - SUM(credit)`; a reversal always returns the balance to its prior value
- **Stock property tests** — movements sum to the displayed balance across every location and ownership type; custody round-trip (out → return) nets to zero; mixed-UOM movements never silently coerce
- **Cost engine golden tests** — §6.4 Test A (عمدة الأحكام: 52,864 / 10.57 / 15.10 at 30% margin-from-price) and Test B (19.5 sheets must not round)
- **Landed cost test** — a mixed import shipment allocates identically under `by_value` regardless of line order; late cost lines change the weighted average forward only
- **Lock tests** — every rule in §10.1 has a test proving the blocked action fails **server-side** when attempted directly via API
- **Offline tests** — replaying the same idempotency key twice creates one movement, not two
- **RBAC tests** — each role attempting every forbidden capability receives 403 from the API, not merely a hidden button; non-manager queries never return cost columns
- **Self-approval test** — an approval where `requested_by = decided_by` is rejected at the database layer
- **Reservation tests** — reserving changes no stock balance (I9); reservations against a job order cannot exceed planned quantity; partial loading deducts only what was loaded and returns the remainder with the reservation left open
- **ATP tests** — `atp(date)` never exceeds `free_now + incoming` for that date, never double-counts a reserved job order quantity, and returns zero rather than negative
- **Shortfall test** — allocation follows ratio DESC then created_at ASC; a manager override is logged
- **Delay propagation test** — changing a job order's expected date raises one notification per customer holding a reservation against it
- **Portal scope test** — every portal and agent endpoint is asserted to return no quantity, reservation, location or availability field, including nested objects and error payloads
- **Dual-track test** — the customer and customs lists derive from one capture; excluding a line changes only the customs document; the customer document is unaffected; issuing the customs list is blocked while an included line lacks page count, paper type or publication year
- **Count-during-trading test** — a movement posted after a line's `counted_at` is applied on top of the counted quantity, not overwritten by it

---

## 17. Open items

None of these block starting the kernel. Each blocks one specific calculation when the build reaches it.

| # | Item | Blocks | Owner |
|---|---|---|---|
| 1 | Paper type → waste % table (3–10% range known) | Cost engine accuracy | Client |
| 2 | Rate master values: price/ton by paper+grammage, price/signature, plate price, binding price by type | Cost engine has structure, no numbers | Client |
| 3 | Category margin % for the four categories | Proposed selling price | Client |
| 4 | Fixed-cost allocation basis across titles in one job order | Multi-title job orders | Client |
| 5 | Discount ceiling 10% — of invoice value or unit price | Discount guard | Client |
| 6 | Chart of accounts | Finance module | Client + accountant |
| 7 | Tax treatment of local vs imported purchases | Purchase posting | Client + accountant |
| 8 | One agreed English transliteration of the company name | Document templates | Client |
| 9 | ~~Physical stock count~~ | **RESOLVED 9 Oct** — 4 days, immediately before go-live, trading continues. See §15.1 | — |
| 10 | Written instruction on the dual document track, reviewed with the customs broker | Customs track implementation | Client + counsel |
| 11 | Confirm the WhatsApp agent inherits the portal's stock-blindness scope | Agent read scope (§7.16) | Client — `INFERRED`, needs a yes |
| 12 | Which shipping lines expose usable tracking | Container position (§7.10) | Client |
| 13 | ~~Online payment gateway~~ | Deferred with the portal (§7.13) | — |
| 14 | Exclusion reason codes — is the current list complete? | Customs list derivation (§9.9) | Client |
| 15 | `print_pricing_method` per printer — which vendors bill per signature, which per ream | Cost engine branch (§6.1) | Client |
| 16 | `pages_per_signature` — 16 in both examples; confirm it does not vary by press | Signature and plate counts (§6.2) | Client |

**Build everything else now.** Where a value is missing, read it from the rate master and fail loudly with a clear message naming the missing key — never silently default a price, a margin or a waste percentage.

---

## 18. Change log

Only decisions that changed a previous instruction are listed. Everything else has been stable since the spec was first written.

| Date | Change |
|---|---|
| 15 Sep | Client answered all 50 scoping questions. |
| 1 Oct | 37 real operational files received. All four blocking decisions answered: single agent as container counterparty; profitability per container; shortfall priority by advance-payment ratio; three managers with identical rights. Five missing requirement sections received. |
| 1 Oct | **Correction** — ملزمة returns to the schema. An earlier instruction to drop it was wrong: plates = signatures × colours. |
| 1 Oct | **Correction** — selling price supports margin-from-price, not only markup-on-cost. Paper is priced per ton with ream price derived from ream weight. |
| 9 Oct | Stock count plan agreed: 4 days, immediately before go-live, trading continues. Closed the longest-standing open item. |
| 9 Oct | WhatsApp agent added to scope. Customer portal agreed with a hard stock-blindness rule. Packing-list dual-track workflow designed (§9.9). Package hold reasons, container tracking, POS natural-language entry, bookshop-with-stock as a POS location. |
| 12 Oct | **Correction** — printers bill per signature *or* per ream, varying by printer. The cost engine branches on a per-vendor setting (§6.1). This settles the signature-versus-ream question for good. |
| 13 Oct | Customer portal removed from the current offer — specified but `NOT IN SCOPE`. WhatsApp agent billed monthly as pass-through cost with no fixed fee. |
| 15 Oct | Spec reconciled against all project sources and memory. Cost-engine divisors derived and cross-validated against both worked examples rather than assumed. Provenance markers added. |
| 15 Oct | **Engineering reconciliation** (INSHARO, approved with the foundation design). §2 stack: NestJS/React/Tailwind replaced by Laravel 12 + PostgreSQL 16 + Bootstrap 5.3 RTL, harvested from the INSHARO ERP platform layer where RTL is proven in production. §13: Bootstrap 5.3 RTL with logical `ms-`/`me-` utilities, not Tailwind. §10.2: nine roles become ten — `system_admin` added for INSHARO support (users, roles, settings, audit log, backups; no business data, no approvals, no bypass). §10.4: `system_admin` column added. |
