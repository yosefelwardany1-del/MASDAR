# MASDAR — ERP build specification

> **Draft 0.1 — not signed off.** Drafted from MASDAR's own documents and public records (§0).
> Everything MASDAR has not decided is marked `OPEN`; where we suggest an answer it reads
> "OPEN — proposed: …" and stays open until MASDAR confirms. The code builds every `OPEN` item
> configurable and fails loudly rather than guessing. Once signed off, this file is the
> authority for the build (`CLAUDE.md`): where anything else disagrees with it, it wins.

## 0. Status

| Item | Value |
|---|---|
| Client | MASDAR FOR TRADING CO., LIMITED ("MASDAR TRADING") — Hong Kong company no. 2959665, business registration no. 72045911 |
| Version / date | Draft 0.1, 2026-11-17 |
| Signed off by | OPEN |

Sources this draft is built from:

| # | Source | What it gave |
|---|---|---|
| S1 | MASDAR's Proforma Invoice template (PDF) | The PI: header, columns, payment terms, the Yiwu address |
| S2 | MASDAR's "Orders management, observation, and evaluation sheet (订单管理，观察，评估表)" (PDF) | The supplier order and its follow-up steps |
| S3 | MASDAR's phase-1 note (quoted in §2) | Scope: ERP and CRM in one; clients, suppliers, shipping companies; order and container follow-up; a chart of accounts that fits China |
| S4 | Public Hong Kong company records (aggregator sites, not the official registry) | Legal form, incorporation date, registered office |

## 1. Invariants

Each is held by PostgreSQL (a CHECK, a generated column or a trigger) and proven by a test
before the module that needs it is built. The house rules in `CLAUDE.md` (append-only business
tables, balances computed and never stored, `numeric(18,4)` money and quantities,
`numeric(18,6)` FX rates) apply throughout and are not repeated here.

| # | Invariant | Held by |
|---|---|---|
| I1 | Every journal entry balances: its debits equal its credits in the book currency. | deferred constraint trigger on journal lines |
| I2 | Journal lines, payments, order-step events, container events, goods receipts and container loads are never updated or deleted; a correction is a new row that reverses or supersedes one. | `BEFORE UPDATE OR DELETE` triggers |
| I3 | Nothing is posted with a date inside a closed accounting period. | trigger on journal lines |
| I4 | An issued PI never changes. A change is a new revision under the same PI number, and exactly one revision of a PI is current. | trigger + partial unique index |
| I5 | On every PI line, total quantity = cartons × quantity per carton, and amount = total quantity × unit price. | generated columns |
| I6 | The cartons of an order line loaded into containers never exceed the cartons received for it. | trigger on container loads |
| I7 | An FX rate is greater than zero, and every amount in a currency other than the book currency carries the rate used. | CHECK |
| I8 | A container's status moves only along its allowed path (§5.5); going back is a correction event naming the event it corrects. | trigger on container events |
| I9 | OPEN — proposed: a client's goods are not loaded until the client has paid the PI in full (S1: "70% final payment before shipping"), unless a manager has recorded an exception for that PI. Built with payments (sub-project 5). | trigger on container loads, if confirmed |

## 2. The business

**Who they are.** MASDAR FOR TRADING CO., LIMITED, trading as "MASDAR TRADING", is a private
Hong Kong company limited by shares, incorporated on 10 July 2020 (S4). Its registered office
is a company-secretary address in Wan Chai, Hong Kong. It works from an office in Yiwu,
Zhejiang, China: Room 1608, Block B, Liandu Building, Futian Subdistrict (S1). Yiwu has the
world's largest wholesale market for small commodities.

**What they do (INFERRED from S1–S3).** MASDAR buys goods from Chinese factories and suppliers
for its clients. It follows each order through samples, artwork, packaging and inspection, then
ships the goods in containers through shipping companies. The client gets a Proforma Invoice
(PI) in US dollars with item photos, carton counts, volume and weight. The terms are 30%
deposit and 70% before shipping (S1).

OPEN: does MASDAR earn a commission on each order (an agent), or the margin between the
client's price and the factory's price (a trader)? The answer decides the income accounts,
whether goods are ever on MASDAR's books, and what the PI's price means (§5.6, Q-A2).

**Their words (S3), verbatim:**

> FIRST PHASE: 1. CUSTOMIZED ERP SYSTEM 2. CUSTOMIZED CRM SYSTEM — TWO in ONE
>
> Our three main interaction points are: 1. Clients 2. Suppliers 3. Shipping Companies
>
> Our TWO essential need for follow up are: 1. Orders Status follow up 2. Container Status
> follow up
>
> شجرة المحاسبة لابد تكون مضبوطة بما يتناسب مع خصوصية وضع الصين
>
> ("The chart of accounts must be set up to fit the particulars of the situation in China.")

**Where and when.** The office is in Yiwu, so the time zone is `Asia/Shanghai` (INFERRED from
S1; it replaces the starter's `Africa/Cairo` in sub-project 1). Clients are abroad. OPEN: which
countries; the WhatsApp default country code and the client currencies depend on it.

**Currencies.** PIs are in USD (S1). OPEN: whether factories are paid in RMB (CNY) as is usual
in Yiwu, which other currencies clients pay in, and the book currency (USD is likely; Hong Kong
tax returns are in HKD — MASDAR's accountant decides).

**Languages.** The PI is in English (S1). The follow-up sheet is in English and Chinese (S2).
The starter's screens are Arabic. OPEN: which screen languages the staff need (Arabic,
English, Chinese) and which languages each printed document needs. Adding a screen language
after the modules exist means revisiting every screen, so this is answered before
sub-project 1.

**Team.** OPEN: how many staff, their jobs (§6) and the languages each reads.

**"The situation in China" — how we read it (INFERRED; to confirm with MASDAR and its
accountant).** The company is in Hong Kong, while the work and the factories are in mainland
China. So:

- The books are a Hong Kong company's. A Hong Kong limited company has its accounts audited
  every year and pays Hong Kong profits tax. Hong Kong has no VAT, so the PI carries no tax
  lines.
- Money moves through several kinds of account and between them, often with a currency
  exchange on the way: the Hong Kong company's bank accounts (USD, maybe HKD), and in the
  mainland RMB bank accounts, Alipay or WeChat Pay, cash, or payment agents.
- Client deposits received before shipping are held against their orders until the goods
  ship.

OPEN: is there also a mainland company (for example a Yiwu company or representative office)
with its own books? The starter is single-company (a guard test fails on a `company_id`
column), so a second set of books must be decided before the ledger is built (Q-A1).

## 3. Shared registries

Every registry has exactly one writer (`CLAUDE.md`). "Never changes" lists what a correction
must do by a new row instead of an edit.

### 3.1 Parties

Clients, suppliers (factories and market shops) and shipping companies (OPEN: which of
forwarders, shipping lines, trucking, customs brokers) live in one registry. S3 names them as
the three interaction points of one system, a party can be more than one kind, and the CRM log
(§3.7) is the same for all three. They appear as three screens so each can be granted
separately (§6).

- Columns: code, name (English), name in Chinese and/or Arabic, kinds (client / supplier /
  shipping company — one or more), country, city, address, default currency, notes, active.
- Clients also: default destination ("Shipped to" on the PI), default payment terms (30/70,
  S1), the account manager, how they came to MASDAR (OPEN).
- Suppliers also: market booth or factory address, what they make, bank or payment details
  (who may see them: §6).
- Contacts per party: name, role, phone in international format, WhatsApp, WeChat ID, email.
- Writer: `PartyService`. Never changes: the code. Once any document refers to a party it is
  deactivated, never deleted.

### 3.2 Products

OPEN: does MASDAR keep a reusable catalog (repeat orders), or is every PI line new sourcing?
Proposed: a catalog, and a PI line may create a product on the spot.

- Columns: code, name and description (English; Chinese OPEN), photos, unit (pcs, sets, dozen
  … — a list), carton spec (quantity per carton, carton size giving CBM per carton, gross KG
  per carton), usual supplier, HS code (OPEN — needed only if MASDAR prepares customs
  documents).
- Writer: `ProductService`.

### 3.3 Documents

- **Proforma Invoice (PI)** — §5.2: header, lines, revisions.
- **Supplier order** — §5.3: one factory or supplier; one row of S2.
- **Booking and container** — §5.5.
- **Payment** — §3.5.

### 3.4 Reference lists

Units; container types with their usable CBM and maximum KG (20GP, 40GP, 40HQ … — the figures
are data, not code); ports (loading: OPEN, e.g. Ningbo, Shanghai; discharge: per client);
shipping modes (sea FCL, sea LCL, air, rail, express — OPEN which MASDAR uses); order steps
(§5.3); currencies.

### 3.5 Money accounts and payments

- Money accounts: each bank account, e-wallet, cash box or payment agent, with its currency
  and where it is (Hong Kong / mainland). OPEN: the real list (Q-A5).
- Payments: one row per movement of money — a client receipt, a payment to a supplier or a
  shipping company, a transfer or exchange between MASDAR's own accounts, an expense. Each has
  date, account, currency, amount, rate to the book currency, counterparty, reference, the
  evidence (bank slip or screenshot) and what it is for (a PI, a supplier order, a container,
  an expense). Append-only (I2).
- Writer: `PaymentService`.
- Balances of clients, suppliers and accounts are `SUM()` over payments and documents, never
  stored.

### 3.6 Chart of accounts and ledger

- Journal entries and lines, append-only and balanced (I1), in periods that close (I3). A line
  carries the account, the amount in the book currency, the original currency and amount, the
  rate, and the party and document it relates to.
- Writer: `PostingService` only, from documents and payments by posting rules. No hand-made
  journal except an adjustment entry approved by a manager.
- The chart: §5.6. The posting rules are editable on the Accounting screen, starting from the
  trader reading of Q-A2 (reading 2026-11-17); a rule or money account left empty makes the
  posting fail with a message naming exactly what to set.

### 3.7 CRM log

Interactions (call, WhatsApp, WeChat, email, meeting, factory visit) and follow-up tasks
against any party: who, when, a summary, the next step and its due date. Writer: `CrmService`.

## 4. Shared engines

| Engine | What it does | Rules |
|---|---|---|
| Numbering | Numbers PIs, supplier orders, containers and payments. | Gapless per series and year; never reused. OPEN: MASDAR's current formats (Q-P1). |
| Order steps | Records each step of a supplier order as an event — done, not applicable, or reopened — with date, user, note and attachments. The follow-up board reads the latest event per step. | Append-only (I2). The step list is data (§5.3). |
| Container events | Records each status change of a container with date, place and evidence. | Append-only; allowed path only (I8). |
| FX | Holds a rate per currency per day and converts to the book currency. | OPEN: the rate source (Q-A3). A payment between currencies records the rate actually obtained. |
| Posting | Turns documents and payments into journal entries by rule. | The ledger's only writer. Refuses until the accounting questions are answered. |
| Approvals | A manager's approval on the documents §6 names. | An approval is a row (who, when); approved means immutable. |
| Notifications | In-app alerts (starter). WhatsApp to clients is OPEN (§8). | §7 lists them. |
| Attachments | Files on parties, products, PIs, supplier orders, steps, containers and payments (starter). | They replace S2's "designated folder" (§5.3). |
| Printed documents | PDFs: the PI exactly in MASDAR's layout (S1). OPEN: a supplier order contract, the packing list, the commercial invoice. | English (S1); other languages OPEN (Q-B2). |

## 5. Modules

### 5.1 CRM — clients, suppliers, shipping companies

S3: "TWO in ONE" — one system, one party registry and one sign-in for the ERP and the CRM.

- Screens: clients, suppliers, shipping companies; a party's page (contacts, interactions,
  tasks, and its PIs, supplier orders, containers and balance); my follow-up tasks.
- Client pipeline: OPEN — proposed stages: lead → inquiry → PI sent → order confirmed (deposit
  received) → in production → shipped → delivered. Every stage after "PI sent" is read from the
  documents, never typed.
- OPEN: do clients, suppliers or shipping companies ever sign in (a portal)? Proposed for
  phase 1: staff only; clients get status updates through a channel (§7).

### 5.2 Proforma Invoice (PI)

The PDF follows S1 exactly: the MASDAR logo, "MASDAR FOR TRADING CO., LIMITED", the Yiwu
address and phone, the title "Proforma Invoice (PI)".

- Header: To (client), Shipped to, PO NO, PO DATE, currency (USD by default), payment terms.
- Lines: No, picture, item name & description, CTN, QTY/CTN, TOTAL QTY (= CTN × QTY/CTN), UNIT,
  PRICE, AMOUNT (= TOTAL QTY × PRICE), Total CBM (= CTN × CBM per carton), Total KG (= CTN × KG
  per carton). Totals of quantity, amount, CBM and KG.
- Footer: the PI's terms, printed as in S1 ("30% DEPOSIT 70% FINAL PAYMENT BEFORE SHIPPING").
  The deposit percentage is set per PI and defaults from the client. OPEN: who may change it.
- Workflow: draft → issued (immutable, I4) → accepted by the client → confirmed when the
  deposit arrives. A change after issue is a new revision; a cancellation carries a reason.
- Each PI line is bought from one supplier (INFERRED: a Yiwu order is usually bought from
  several suppliers). The PI shows the client's price only — never the supplier or the
  supplier's price.
- OPEN (Q-P1, Q-P3): is "PO NO" the client's purchase-order number or MASDAR's own number? Is
  there a quotation before the PI? Are freight and other charges on the PI or invoiced
  separately?

### 5.3 Supplier orders and the order follow-up (essential need 1)

A supplier order is one factory or supplier's part of a client order — one row of S2. Its lines
are PI lines, so a PI bought from five suppliers gives five supplier orders. OPEN: can one
supplier order combine several clients' PIs? (The model allows it.)

- Header (S2): factory/supplier, order no, confirmation date, delivery date. The delivery date
  is the supplier's promise; a change is recorded as a new date, never overwritten, so
  slippage can be measured. Also the supplier's price per line (RMB by default) and the
  supplier's deposit and payment terms.
- Steps, in S2's order. Each step is marked done or not applicable, with date, user, note and
  attachments:
  1. Order contract confirmation & stamp — the stamped contract attached.
  2. Order uploaded to designated folder — OPEN: drop it (the attachment in step 1 is the
     folder) or keep it as a check.
  3. Deposit — OPEN: the deposit paid to the factory, the client's deposit to MASDAR, or both.
     Proposed: the factory deposit; the client's deposit shows on the PI.
  4. Initial sample confirmation — photos attached.
  5. Artwork confirmed — artwork files attached.
  6. Final sample confirmation.
  7. Labeling and packaging.
  8. Examine & receive goods & update in the system — the goods receipt: cartons received per
     line, the inspection result (pass / fail / pass with notes), photos, measured CBM and KG.
     OPEN: where goods are received (MASDAR's warehouse, the forwarder's, the factory).
  9. Booking shipping — links the order to a booking (§5.5).
  10. Loading the container — completed by the container loads (§5.5).
  11. Money transfer to factory — the final payment to the supplier.

  OPEN: must the steps happen in this order? (Proposed: no — shown in order, recorded as they
  happen.) Do some steps not apply to some orders (e.g. no artwork for stock goods)? What due
  date does each step get? (Proposed: set per order, defaulting from the delivery date by
  offsets MASDAR chooses.)
- Until sub-project 5 adds payments, the two money steps (3 and 11) are recorded as steps with
  the amount and evidence attached; sub-project 5 links them to payments.
- The follow-up board: S2 as a screen. One row per supplier order, one column per step, each
  cell showing done, due or late. Filters by client, supplier, follower and status; late rows
  first.
- Supplier evaluation (S2's title: "management, observation, and evaluation"): per supplier,
  on-time delivery against the promised date, sample rounds and inspection failures (§7).
  OPEN: any score MASDAR already uses.

### 5.4 Goods between receipt and loading

INFERRED: MASDAR holds goods only between receipt (step 8) and loading (step 10). What is on
hand is received − loaded per order line, computed and never stored. OPEN: does MASDAR keep any
stock of its own for sale?

### 5.5 Shipping and the container follow-up (essential need 2)

- Booking: shipping company, mode (§3.4), booking no, port of loading, port of discharge, ETD,
  ETA, vessel and voyage, B/L no.
- Container: number, type, seal no, its booking; its capacity comes from its type.
- Loads: which order lines and how many cartons (no more than received, I6), giving CBM and KG
  loaded and the fill against capacity. A container may carry several clients' goods, and an
  order may go in several containers (INFERRED: consolidation is common in Yiwu; OPEN to
  confirm, Q-C3).
- Status path (events, I8): booked → empty container at the warehouse → loading → loaded &
  sealed → gated in at port → on board (departed) → in transit → arrived at port of discharge
  → released / delivered. OPEN: MASDAR's own list of statuses (Q-C2).
- The container board: every container with its status, ETD and ETA, days late, the clients
  and orders inside and its documents.
- Documents per container — B/L, packing list, commercial invoice, certificate of origin,
  loading photos — are attached in phase 1. OPEN: which the system should produce (proposed:
  the packing list, from the loads).
- Costs per container: freight, trucking from Yiwu to the port, customs declaration and so on.
  OPEN: are they recharged to clients, and how does a shared container split them? (Proposed:
  by CBM loaded.)

### 5.6 Accounting — the chart of accounts for China

Blocked until Q-A1 to Q-A4 are answered. The chart below is INFERRED and only a starting point
for MASDAR's accountant. Each account has a currency (or "any"), and each real money account
(§3.5) gets its own sub-account.

| Code | Account | Notes |
|---|---|---|
| 1 | **Assets** | |
| 11 | Cash and bank — Hong Kong | one sub-account per Hong Kong bank account and currency |
| 12 | Cash and bank — mainland China | RMB bank accounts, Alipay, WeChat Pay, RMB cash |
| 13 | Money in transit | transfers and exchanges between MASDAR's own accounts, Hong Kong ↔ mainland, until they arrive |
| 14 | Client receivables | per client and currency |
| 15 | Deposits paid to suppliers | per supplier, until the goods are received |
| 16 | Goods received, not yet shipped | only if MASDAR is a trader (Q-A2) |
| 17 | Other receivables and prepayments | rent deposit, prepaid expenses, staff advances |
| 18 | Fixed assets | office equipment, net of depreciation |
| 2 | **Liabilities** | |
| 21 | Client deposits received | advances held against PIs until the goods ship |
| 22 | Supplier payables | per supplier, usually RMB |
| 23 | Shipping company payables | per shipping company |
| 24 | Accrued expenses | audit fee, rent, salaries |
| 25 | Directors' / shareholders' current accounts | money paid in or drawn by the owners |
| 26 | Hong Kong profits tax payable | |
| 3 | **Equity** | share capital, retained earnings, current-year result |
| 4 | **Income** | sales of goods (trader) **or** commission income (agent) — Q-A2; service fees (sourcing, inspection); freight recharged; FX gains |
| 5 | **Cost of sales** | purchases from suppliers (trader only); freight and port charges; trucking to port; inspection; labels and packaging |
| 6 | **Operating expenses** | Yiwu office rent; salaries; staff visas and residence permits; Hong Kong company secretary and registered office; audit and accounting; bank and transfer charges; communications; travel; warehouse |
| 7 | **Other** | FX gains and losses (realised, unrealised); bank interest |

Reports from the ledger: trial balance, profit and loss and balance sheet, in the book currency
and per currency; client, supplier and shipping-company statements; cash position per account
and currency.

### 5.7 HR — employees, leave, attendance and payroll

Added 2026-11-18 at our proposal: S3 does not ask for it, and MASDAR has not yet said what its
HR work looks like (Q-H1). Built like phase 1: every choice MASDAR has not made is a setting
or an editable list, and the screen says so where a missing setting matters.

**Employee file.** Code (`EMP-0001`, never changes), name and local (Chinese) name, job title,
department, work location (Yiwu office, Hong Kong, warehouse …), employment type, nationality,
contact details, emergency contact, ID or passport number, salary bank details, joining date
and leaving date. An employee may be linked to one user account, which lets them request
their own leave. ID numbers and bank details are shown only to HR and payroll.

- **Salary history**, append-only: base salary and fixed monthly allowances, in a currency,
  from a date. The salary in effect on a date is the latest row on or before it; a change or a
  correction is a new row. Seen only by those who see payroll.
- **Documents**, append-only: passport, residence permit, work permit, visa, labour contract,
  health check … each with its number, issue and expiry dates and the scan. A renewal is a new
  row of the same kind; the newest is current. A current document of an employee still on the
  staff that expires within N days (setting, proposed 60) or has expired is on the home page
  and in the morning summary of HR and managers. For foreign staff in Yiwu the residence
  permit is the one that matters most.

**Leave.** Leave types are a list (annual, sick, unpaid, marriage, maternity, paternity,
bereavement to start), each with its days a year (blank: not counted) and whether it is
paid. An employee's allowance for a year can be set apart from the type's (e.g. statutory annual
leave of 5, 10 or 15 days by years worked). A request (employee, type, from, to, days — half
days allowed) is append-only; its decisions (approved, rejected, cancelled) are rows of their
own, and its status is the latest. Balance = allowance − approved days that year, computed.
A request beyond the balance is refused. Nobody decides their own request. Approvers are told
of a new request; the employee is told of the decision.

**Attendance.** A monthly sheet per employee and day. Nothing recorded on a working day means
present; HR records absences, half days, late arrivals, remote days and overtime hours.
Records are append-only; the latest for a day counts. The working week and the public
holidays (including China's make-up working days) are settings and a list — the holidays are
not guessed: the sheet warns while the year has none.

**Payroll.** A monthly run per currency (`PR-2026-0001`): draft → approved → (void). Preparing
a draft makes one line per employee on the staff that month with a salary in that currency:

| Line | From |
|---|---|
| Base salary, allowances | the salary in effect at the month end, prorated by working days for a joiner or leaver |
| Overtime pay | overtime hours × base ÷ (days a month × 8) × the overtime multiplier (setting, proposed 1.5) |
| Unpaid days | approved leave of unpaid types and recorded absences (a half day counts ½) in the month |
| Deduction for unpaid days | unpaid days × base ÷ days a month (setting, proposed 21.75, China's standard) |
| Social insurance and housing fund | employee and employer shares at the rates set, on the base salary within the bases set — OPEN (Q-H3): no rate is assumed; a run warns while they are not set |
| Individual income tax | entered per line — OPEN (Q-H4): withheld as the tax bureau's system computes it |
| Bonus, other deductions | entered per line |

Gross = base + allowances + overtime + bonus − deduction; net = gross − employee shares − tax
− other deductions. Both are computed by PostgreSQL. A draft's lines can be edited; approving
needs a manager and freezes the run. An approved run posts from the accounting queue:

| Dr | Cr |
|---|---|
| 6201 Salaries (gross) | 2402 Salaries payable (net) |
| 6203 Employer social insurance and housing fund | 2403 Social insurance and housing fund payable (both shares) |
| | 2404 Individual income tax withheld |
| | 1703 Staff advances (other deductions) |

Salaries are paid as payments of kind "salary payment" against the run (Dr 2402 / Cr the money
account); the run shows paid against net. A run is voided only with no salary payment counting
and its entry reversed; the month can then be run again. Payslips print per employee, in
English with Chinese labels.


## 6. Roles and permissions

OPEN — proposed roles, from the jobs S2 and S3 imply. They replace the starter's `supervisor`
and `staff`; `manager` and `system_admin` stay as the starter defines them.

| Role | Job |
|---|---|
| `manager` | the owner or general manager: every screen and every approval |
| `sales` | account managers: clients, CRM, PIs |
| `merchandiser` | order followers: supplier orders, steps, samples, artwork |
| `warehouse_qc` | receiving and inspecting goods (step 8), loading |
| `logistics` | bookings, containers, shipping companies |
| `accountant` | payments, FX, ledger, statements |
| `hr` | HR and administration (§5.7): the employee file, leave, attendance, preparing payroll |
| `system_admin` | the vendor's support: users, roles, settings, audit log — no business data |

Proposed grants (`view` ⊂ `edit` ⊂ `approve`; — = none):

| Screen | manager | sales | merchandiser | warehouse_qc | logistics | accountant |
|---|---|---|---|---|---|---|
| Clients (with CRM log) | approve | edit | view | — | view | view |
| Suppliers | approve | — (OPEN) | edit | view | — | view |
| Shipping companies | approve | — | view | — | edit | view |
| Products | approve | edit | edit | view | view | view |
| Proforma invoices | approve | edit | view | — | view | view |
| Supplier orders & follow-up | approve | view | edit | view | view | view |
| Goods receipt | approve | — | view | edit | view | view |
| Containers | approve | view | view | edit | edit | view |
| Payments | approve | view | — | — | — | edit |
| Accounting | approve | — | — | — | — | edit |
| Employees (§5.7) | edit | — | — | — | — | — |
| Leave & attendance | approve | — | — | — | — | — |
| Payroll | approve | — | — | — | — | view |

The `hr` role: Employees edit, Leave & attendance edit, Payroll edit (preparing; a manager
approves). Everybody linked to an employee file requests their own leave ("My leave").

Screen grants cannot hide a column or a row. Two rules are built into the screens themselves:
hiding supplier identities and prices from a role that may see a screen showing them, and
limiting a sales user to their own clients. OPEN, each with a proposal (Q-R1):

- Who sees supplier identities and supplier prices — proposed: manager, merchandiser,
  accountant. OPEN whether sales does, since sales sets the client's price.
- Does a sales user see only their own clients? Proposed: yes.
- What needs a manager's approval? Proposed: issuing a PI, a supplier order's prices, every
  payment out, a loading exception (I9), an adjustment journal entry, closing a period.
- Supplier bank details: proposed manager and accountant only.

## 7. Reports and notifications

| Report / alert | Content | Who | When / channel |
|---|---|---|---|
| Order follow-up board | §5.3 | merchandisers, manager | screen |
| Container board | §5.5 | logistics, sales, manager | screen |
| Step overdue | a step past its due date; a delivery date passed with no receipt | the order's follower, manager | in-app, daily |
| Delivery date moved | a supplier changed the promised date | follower, the client's account manager | in-app |
| Unpaid before shipping | a PI not paid in full while its goods are booked | account manager, accountant | in-app |
| Departure and arrival | departed; arriving in N days (OPEN: N); arrived | account manager; the client (OPEN) | in-app; client channel OPEN |
| Client statement | PIs, receipts and balance per currency | accountant; the client (OPEN) | on demand, PDF |
| Supplier statement | orders, deposits, payments, balance | accountant | on demand |
| Supplier evaluation | on-time %, sample rounds, inspection failures | manager, merchandisers | monthly (OPEN) |
| Cash position | balance per account and currency | manager, accountant | screen |
| Financial statements | trial balance, profit and loss, balance sheet | manager, accountant, auditor | on demand |
| Employee documents expiring | a current residence permit, visa, passport or contract expiring within N days or expired | HR, manager | in-app, daily |
| Leave to decide | leave requests waiting for a decision | those who approve leave | in-app, on request and daily |

OPEN (Q-C4): which status changes a client is told of, through which channel (WhatsApp, email)
and in which language.

## 8. Integrations

| System | Use | Credentials | When it is down |
|---|---|---|---|
| WhatsApp (Meta Cloud API, starter) | status updates to clients — OPEN | env only (starter) | messages wait and retry; no order is blocked |
| WeChat | OPEN: needed? Many Chinese suppliers work on WeChat. No integration in phase 1; WeChat IDs are kept on contacts. | — | — |
| Container tracking (shipping line or tracking service) | OPEN, later phase: statuses updated from the B/L or container number | env only | manual updates continue |
| Email | PIs and statements to clients — OPEN | env only (starter mailer) | sending waits; the PDF can be downloaded |
| AI (starter, drafts only) | OPEN, later phase: e.g. draft a PI from a client's product list and photos | env only (starter) | the feature is hidden |
| Tax e-invoicing | None for the Hong Kong company (no VAT). OPEN: mainland fapiao only if a mainland company exists (Q-A1). | — | — |

## 9. Data migration and go-live

- Today: Excel sheets (S1 and S2 are exports of them) and a shared "designated folder" (S2).
  OPEN (Q-M1): S2 says "update in the system" — which system is used today, and does anything
  move from it?
- To bring over — OPEN, proposed: clients, suppliers and shipping companies with their
  contacts; products with photos, if there is a catalog; open PIs and supplier orders with the
  steps done so far; containers not yet delivered; opening balances at the cut-over date per
  client, supplier, shipping company and money account, per currency, agreed with MASDAR's
  accountant.
- Imports go through the starter's Excel import, into each registry through its own writer.
- Cut-over — OPEN (Q-M2), proposed: the follow-up goes live first (sub-projects 1–4) while the
  ledger is agreed; accounting goes live at the start of a period with opening balances.

## 10. Build order

Each sub-project gets its own spec → plan → build cycle, with a review by us and MASDAR before
the next starts. The two follow-ups come first: they are MASDAR's stated essential needs (S3),
and none of the accounting questions block them.

| # | Sub-project | Needs answered first |
|---|---|---|
| 1 | Foundations: name, logo, colours, time zone, languages, roles; clients, suppliers and shipping companies with contacts, the CRM log and tasks | Q-B1–Q-B3, Q-R1, Q-R2, Q-K1 |
| 2 | Products and the Proforma Invoice (MASDAR's layout, revisions, terms) | Q-P1–Q-P3 |
| 3 | Supplier orders and the follow-up board; goods receipt and inspection; supplier evaluation | Q-O1–Q-O5 |
| 4 | Bookings, containers, loads and the container board; status updates to clients | Q-C1–Q-C4 |
| 5 | Money accounts, payments and FX; then the chart of accounts, the ledger and the financial reports | Q-A1–Q-A6 |
| 6 | Migration of open orders and containers, opening balances, go-live | Q-M1, Q-M2 |
| 7 | HR: employee file and documents, leave, attendance, payroll (§5.7) | Q-H1–Q-H5 |

## 11. Open questions

Grouped by the sub-project they block (§10); the first group is needed first.

| # | Question | Who answers | Blocks |
|---|---|---|---|
| Q-B1 | Brand: the Arabic name (if any), the logo file, the brand colours | MASDAR | 1 |
| Q-B2 | Languages: on screen (Arabic / English / Chinese) and for whom; for each printed document | MASDAR | 1 |
| Q-B3 | The clients' countries; time zone `Asia/Shanghai` confirmed; the WhatsApp default country code (or always require the international code) | MASDAR | 1 |
| Q-R1 | Staff numbers and jobs; the roles and grants in §6; who sees supplier identities and prices; sales limited to own clients; what needs a manager's approval | MASDAR | 1 |
| Q-R2 | Do clients, suppliers or shipping companies ever sign in (a portal)? | MASDAR | 1 |
| Q-K1 | The client pipeline stages; how clients come to MASDAR | MASDAR | 1 |
| Q-P1 | Is "PO NO" on the PI the client's number or MASDAR's? Number formats in use today? Is there a quotation before the PI? | MASDAR | 2 |
| Q-P2 | A reusable product catalog, or new items on every PI? Chinese product names? HS codes? | MASDAR | 2 |
| Q-P3 | Freight and other charges: on the PI or separate? Who may change the 30/70 terms? | MASDAR | 2 |
| Q-O1 | Can one supplier order combine several clients' PIs? | MASDAR | 3 |
| Q-O2 | The "Deposit" step: the factory's deposit, the client's, or both? | MASDAR | 3 |
| Q-O3 | Keep "uploaded to designated folder" as a step? Must the steps go in order? Which steps can be skipped, and for which orders? | MASDAR | 3 |
| Q-O4 | Where are goods received and inspected? Can more be received than ordered? | MASDAR | 3 |
| Q-O5 | Due dates for each step; any supplier score already in use | MASDAR | 3 |
| Q-C1 | Shipping modes used; loading ports | MASDAR | 4 |
| Q-C2 | The container statuses MASDAR tracks | MASDAR | 4 |
| Q-C3 | Shared containers (several clients)? How shared costs split; are shipping costs recharged to clients? | MASDAR | 4 |
| Q-C4 | Which status changes the client is told of, how and in which language; which shipping documents the system produces | MASDAR | 4 |
| Q-A1 | Only the Hong Kong company, or also a mainland company with its own books? | MASDAR | 5 |
| Q-A2 | Agent (commission) or trader (buys and resells)? If commission: the rate and what it is charged on | MASDAR and its accountant | 5 |
| Q-A3 | The book currency; the currencies in use; where FX rates come from | MASDAR's accountant | 5 |
| Q-A4 | The real chart of accounts (§5.6 is a draft); the financial year end; the auditor's needs | MASDAR's accountant | 5 |
| Q-A5 | The money accounts (banks, Alipay / WeChat Pay, cash, payment agents) and how money moves between Hong Kong and the mainland | MASDAR | 5 |
| Q-A6 | I9: is loading blocked until the client has paid in full, with manager exceptions? | MASDAR | 5 |
| Q-M1 | The system used today ("update in the system", S2) and what moves from it and from the Excel sheets | MASDAR | 6 |
| Q-M2 | Cut-over dates; the opening-balance date | MASDAR and its accountant | 6 |
| Q-H1 | Does MASDAR want HR in the system? How many staff, where (Yiwu, Hong Kong), which nationalities; who does HR today | MASDAR | 7 |
| Q-H2 | Leave: the types and days a year; the working week (Monday–Friday or –Saturday); who approves | MASDAR | 7 |
| Q-H3 | Social insurance and housing fund: are staff enrolled (foreign staff too)? The rates and the contribution bases for Yiwu | MASDAR's accountant or payroll agent | 7 |
| Q-H4 | Individual income tax: who computes it today; any staff paid from Hong Kong (MPF)? | MASDAR's accountant | 7 |
| Q-H5 | Pay day; paid from which account; overtime rules; how payslips reach staff | MASDAR | 7 |

## 12. Readings

Decisions settled during the build from this spec (marked `INFERRED`) or with the client
(marked `DECIDED`), dated, one per row.

| Date | Mark | Reading |
|---|---|---|
| 2026-11-17 | DECIDED | The client is MASDAR: `APP_NAME=MASDAR` in `.env.example` and `render.yaml`, Render service `masdar`. |
| 2026-11-17 | INFERRED | The legal entity is MASDAR FOR TRADING CO., LIMITED, a Hong Kong company (S1, S4). The books are a Hong Kong company's until Q-A1 says otherwise. |
| 2026-11-17 | INFERRED | Time zone `Asia/Shanghai`: the office is in Yiwu (S1). Applied in sub-project 1. |
| 2026-11-17 | INFERRED | PIs are in USD and English, with 30% deposit / 70% before shipping by default (S1). |
| 2026-11-17 | INFERRED | A supplier order is one factory or supplier — one row of S2 — and its steps are S2's columns in S2's order. |
| 2026-11-17 | INFERRED | One registry holds clients, suppliers and shipping companies (S3: three interaction points, ERP and CRM in one). |
| 2026-11-17 | INFERRED | Orders and containers are many-to-many (consolidation is common in Yiwu); built that way even if MASDAR only ships one order per container. |
| 2026-11-17 | INFERRED | The PI never shows a supplier or a supplier's price (S1 has no such column). |
| 2026-11-17 | INFERRED | The two follow-ups are built before accounting (S3 names them as the essential needs; the accounting questions do not block them). |
| 2026-11-17 | DECIDED | Build all of phase 1 now rather than in six steps. Every OPEN item becomes something MASDAR can change later without code: a business option or an editable list on the Lists & options screen, a step on the follow-up board, or a grant in the role editor. The answers to §11 then become settings, not rebuilds. |
| 2026-11-17 | INFERRED | §6's proposed roles and grants are the defaults; the role editor changes them. Proposed approvals (issuing a PI, every payment out) are switches, on by default. |
| 2026-11-17 | INFERRED | Q-B2 is answered by building all three: each user picks Arabic, English or Chinese. Printed documents stay English, like S1. |
| 2026-11-17 | INFERRED | MASDAR's logo and colours (navy, gold) come from S1's letterhead. The Arabic name stays OPEN; "MASDAR TRADING" stands in. |
| 2026-11-17 | INFERRED | Supplier identities and prices are visible to whoever may view the Suppliers screen. A switch extends this to sales (Q-R1). Another switch limits sales to the clients they manage, on by default. |
| 2026-11-17 | INFERRED | I9 (no loading before full payment) is a switch, off by default. When it is on, a manager can still override, and the override is audited. |
| 2026-11-17 | INFERRED | The posting rules assume a trader (Q-A2). For an agent, point the client_invoice rule's credit at 4201 Commission income on the Accounting screen. |
| 2026-11-18 | INFERRED | Q-O2: a supplier payment moves the order's payment steps. The first ("Deposit") is done once some of the order is paid; all of them ("Money transfer to factory") once all of it is. Only payments that count do so, and only a reversal takes a step back. Giving those steps another kind on Lists & options turns it off. |
| 2026-11-18 | INFERRED | Undoing what completed a step reopens it: unbooking an order from its last shipment reopens "Booking shipping", and unloading reopens "Loading the container". A step marked not applicable stays so. |
| 2026-11-18 | INFERRED | A cancelled supplier order (only possible with nothing received) releases its PI lines, so they can be ordered from another factory. Reopening takes them back unless one was ordered elsewhere meanwhile. |
| 2026-11-18 | INFERRED | Q-C4 is built as an option, off by default. When on, clients with goods in a container get MASDAR's approved WhatsApp template when it reaches one of the chosen statuses (on board and arrived to start). There is no update on a correction. Every attempt is logged. |
| 2026-11-18 | INFERRED | §7 "Unpaid before shipping" goes in the morning summary to the account manager (their clients), the accountant and managers: a current PI booked on a shipment while its counted receipts fall short of its total. |
| 2026-11-18 | INFERRED | Q-R2: phase 1 stays staff-only, as §5.1 proposes. Clients hear about their containers over WhatsApp (above) instead of signing in. Container tracking from shipping lines and AI drafting stay later phases (§8). |
| 2026-11-18 | INFERRED | §5.7 HR is added at our proposal, before MASDAR has answered Q-H1–Q-H5. Every rate and allowance is a setting; social-insurance rates and public holidays start empty and the screens warn; income tax is entered per line, never computed. |
| 2026-11-18 | INFERRED | Q-H2: the working week starts as Monday–Friday and the days a month as 21.75 (China's standard for a daily wage); leave types start with China's common statutory days (annual 5, marriage 3, maternity 158 and paternity 15 as in Zhejiang, bereavement 3). All editable. |
| 2026-11-18 | INFERRED | A new `hr` role prepares; a manager approves leave and payroll; the accountant sees payroll to post and pay it. Salaries are visible only to those who see payroll. |
