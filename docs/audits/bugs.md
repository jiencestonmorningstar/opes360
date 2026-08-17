# Adversarial Bug Hunt — Money & Tenancy Paths

Read-only audit. No code changed. Each finding marked **CONFIRMED** (an exact
failing path was traced) or **SUSPECTED** (plausible, needs a confirming test or
runtime condition). File:line references are to the working tree at audit time.

Scope: (1) Ledger::post callers; (2) VAT/rounding/XAF; (3) refunds/returns/
cancellations; (4) tenancy + public-token controllers; (5) races / lockForUpdate;
(6) midnight-trap date bounds; (7) status columns trusted over the engine.

Counts: **23 findings** — 15 CONFIRMED, 8 SUSPECTED.
By area: Ledger/idempotency 2 · Races/stock 7 · Status-trust 4 · Tenancy/public 6 ·
VAT/rounding 2 · Dates 2.

---

## 1. Ledger::post and idempotency

### 1.1 CONFIRMED — Ledger idempotency is check-then-act with no lock and no unique index
`app/Services/Accounting/Ledger.php:81-97`, `entryFor()` `:154-163`.
`entryFor()` is a plain non-locking `SELECT ... first()`. Inside the posting
transaction two concurrent replays of the same source (webhook delivered twice,
sync envelope replayed, queued job retried) both read `null` and both `INSERT`,
doubling the books. The DB does not catch it: `database/migrations/2026_08_07_000001_create_ledger_tables.php:58`
uses `nullableUlidMorphs('source')` — a **non-unique** index on
`(source_type, source_id)`. Grep of `database/migrations` for `source_type`/
`source_id` returns zero unique-index hits. The only defence is the racy SELECT.
Fix shape: `unique(['company_id','source_type','source_id'])` + catch duplicate-key
and re-read. (Reversals post with `source = null` at `:141`, so a 3-column unique
index is safe today.)

### 1.2 CONFIRMED — a swallowed posting failure still marks a bank line reconciled
`app/Services/Banking/Reconciler.php:334-358`. `recordFromStatement()` posts the
entry through `books->recordQuietly(...)`, which returns `null` on any failure
(RecordsBusinessEvents.php:236-247). The code then unconditionally sets the line
`status = 'matched'` with `journal_entry_id => $entry?->id` (i.e. `null`). A
statement line is marked reconciled with no journal entry behind it — the money
is now invisible to the books and the line will never be offered for matching
again. Contrast the deliberate fail-loud choice in AccountTransfers (see 1.3).

### 1.3 Cleared — AccountTransfers correctly does NOT swallow
`app/Services/Banking/AccountTransfers.php:28-80` posts directly through
Ledger::post inside its own transaction, so a posting failure rolls the transfer
back. This is the correct pattern and the docblock explains why; noted as the
contrast case for 1.2.

---

## 2. Races / missing lockForUpdate

### 2.1 CONFIRMED — Document issuance: status checked outside the transaction → double stock decrement
`app/Services/DocumentIssuer.php:37-51`. The `status !== Draft` guard reads a stale
in-memory `$document` before the transaction opens and never re-reads under
`lockForUpdate()`. Two concurrent issues (double-click, retried job, sync replay)
both pass. `StockLedger::recordSale()` (`app/Services/Stock/StockLedger.php:116-162`)
has **no idempotency guard** (unlike `reverseSale()` at :76-91), so stock is
decremented twice; two numbers burned; two verification tokens (second wins,
orphaning the first). Template for the fix is `PaymentRecorder::record`
(`app/Services/PaymentRecorder.php:71-76`), which re-reads under a row lock.

### 2.2 CONFIRMED — Sales-order reservations computed outside any lock → oversell
`app/Services/Orders/Fulfilment.php:207-215` (inside confirm()'s transaction but
unlocked). `StockReservations::availableOf` (`app/Services/Stock/StockReservations.php:134-137`)
is `onHand() - reservedOf()`, both plain `SUM()` aggregates over movements/
reservations (`:122-131`, `:176-183`) — no lockable row. Two concurrent confirms
of the same item each read available=10, each reserve 10 → shelf promised 20.
The re-check inside `reserve()` (`:57-71`) re-runs the same unlocked aggregate in
the same snapshot, so it does not help. `rereserve()` (`:601-615`) is identical.
`deliver()` trusts cached `quantity_reserved` (`:348,:363`), converting
over-reservation into over-delivery. Needs a lockable per-item row.

### 2.3 CONFIRMED — deliver() checks order status outside the transaction → same goods ship twice
`app/Services/Orders/Fulfilment.php:336-342`. No lock on the order/lines. Two
concurrent `deliver()` both read `quantity_reserved = 10`, both pass the
`$quantity - $reserved > 0.0005` check at `:363`, both write delivery notes and
negative `StockMovement` rows (`:422`), and both decrement `quantity_reserved`
(`:435`, going negative). `cancel()` (`:558-564`) reads `sum('quantity_delivered')`
before the transaction and can race a concurrent deliver.

### 2.4 CONFIRMED — Stocktake post and Production complete: status guard outside the transaction
`app/Services/Stock/Stocktaker.php:137-143` — concurrent posts both pass →
adjustment movements written twice. `app/Services/Manufacturing/Production.php:125-132`
— components consumed twice / finished goods produced twice; the shortage check
(`:145-153`) uses another unlocked `valuation->quantities()` aggregate. `start()`
(`:105-106`) has no transaction at all.

### 2.5 CONFIRMED — openBlock() computes max(range_end) with no lock → overlapping number ranges
`app/Services/DocumentNumbers.php:261-277`. Reached exactly at block exhaustion
(when concurrent requests are most likely). Both compute the same `$highest` and
create two overlapping leases with identical ranges → duplicate numbers. The
`unique(['company_id','type','number'])` at `2026_07_27_000006_create_sales_tables.php:83`
catches the collision as a 500 but leaves the lease ledger permanently corrupt.
`leaseFor()` (`:123-127`) already does the `lockForUpdate()` this path omits.
Note: the primary `allocate()` path (`:226-251`) is correctly locked.

### 2.6 CONFIRMED — claim() advances only a watermark, allowing duplicate offline numbers
`app/Services/DocumentNumbers.php:180-184`. Consumption only moves a watermark
forward. A device claiming 42 then 40 (out-of-order sync, which the comment says
happens) silently accepts and returns 40 — which may already be on another synced
document. No per-number record exists to detect the duplicate; the collision only
surfaces at the unique index and one sync envelope then fails permanently.

### 2.7 SUSPECTED — claim() ignores lease expiry
`app/Services/DocumentNumbers.php:173` rejects `revoked`/`expired` **status** but
never checks `expires_at` (set to now()+30d at `:140`). A lease past expiry whose
status was never swept still accepts claims. No status-sweeper found.

### 2.8 Cleared — TicketSeller / VipMemberships / DeliveryReceiver
`app/Services/TicketSeller.php:43-49` locks the `TicketType` rows before
`remaining()` and increments `sold` on the same locked rows in the same
transaction — no oversell. This is the model the stock services should copy.
`VipMemberships.php:67-70` locks the membership row. DeliveryReceiver is
append-only receiving and cannot oversell.

---

## 3. Status columns trusted where the engine should be asked

### 3.1 CONFIRMED — a paid document can be voided under concurrency (cached column, no lock)
`app/Services/DocumentConverter.php:255-269`. The `amount_paid > 0` guard reads
the cached column on a stale in-memory model, outside the transaction, no
`lockForUpdate()`. Sequence: void reads `amount_paid = 0` → a PaymentRecorder
transaction commits `amount_paid = 500` → void commits, forcing `balance = 0`
and `status = Void`. Result: a voided document with a real payment allocated to
it — money in the till against a sale the system says never happened. The
authoritative figure is `allocations()->sum('amount')`, never consulted.

### 3.2 CONFIRMED — credit-note over-crediting: creditable amount computed outside the transaction
`app/Services/DocumentConverter.php:122-139`. The `$amount - $available > 0.005`
check spans the transaction boundary with no lock on the invoice.
`creditableAmount()`/`creditedTotal()` (`:73-86`) are aggregate reads over child
documents. Two concurrent full-remaining credit notes both see the same
`$available` and both commit → invoice credited beyond its total, customer
account negative. The single-request over-credit guard is correct; the race is
the hole.

### 3.3 CONFIRMED — unguarded double-reversal in void paths (ExpenseRecorder, PayrollRunner, Stocktaker)
`app/Services/ExpenseRecorder.php:168-172`, `app/Services/Payroll/PayrollRunner.php:258-264`,
`app/Services/Stock/Stocktaker.php:240-245`. Each void sets `status = 'void'` then
calls `entryFor()` + `reverse()`. `entryFor()` (Ledger.php:154-163) filters
`whereNull('reverses_entry_id')`, so it always returns the *original* entry, never
a prior reversal. Two concurrent voids (or a void replayed after a failed
response) each find the original and each post a full reversal — the entry is
reversed twice, over-crediting/over-debiting the books by the entry's full value.
No status re-read under lock guards the reversal. (PayrollRunner locks the run in
approve/markPaid but `void()` at `:249-268` does not lock before reversing.)

### 3.4 SUSPECTED — PaymentRefunder recomputes document status from the same unlocked cached column
`app/Services/PaymentRefunder.php:164-181`. `restoreDocuments()` locks the document
(`:158`) which is good, but the status `match` derives from `$document->status`
(cached) and `amount_paid`/`total` cached columns rather than re-summing
allocations. Preserving `Void` is right; the concern is drift in the cached
columns feeding the status decision. Not traced to a concrete failing interleave.

---

## 4. Tenancy / public-token controllers

### 4.1 CONFIRMED — SetCurrentCompany never clears a stale company on the guest branch
`app/Http/Middleware/SetCurrentCompany.php:26-30`. When there is no authenticated
user the middleware `return $next($request)` without `current->set(null)`.
`CurrentCompany` is a container singleton. Under php-fpm the container is fresh
per request (safe today), but under any long-lived worker (Octane/Swoole/
RoadRunner, shared queue container) a guest hitting `/v/`, `/e/`, `/f/` inherits
the previous request's tenant, and every "public" page then resolves scoped
relations as an unrelated company. This is the one place that must fail closed and
does not. It also silently masks 4.2 in the test suite (tests seed CurrentCompany
in setUp).

### 4.2 CONFIRMED — EventPublicController never establishes company context
`app/Http/Controllers/EventPublicController.php` — no `CurrentCompany` import; the
only public controller that neither wraps in `CurrentCompany::as()` nor detaches
the scope. `TicketType` is tenant-scoped and `Event::ticketTypes()` is a plain
`hasMany`. So `'types' => $event->ticketTypes` (`:34`, `:58`) resolves
`where company_id = null` → empty; the sales page shows no tickets. In
`TicketSeller::sell` (reached from `:82`), `TicketType::query()->whereKey(...)`
(`app/Services/TicketSeller.php:44-49`) scopes to null → 0 rows → every purchase
fails "Choose at least one ticket." Confirmation page `with(['ticketType',
'verificationToken'])` (`:122`) renders blank QR. Availability break today; a
cross-tenant read if 4.1's stale-singleton condition holds. Fix: wrap all methods
in `CurrentCompany::as($event->company, ...)`.

### 4.3 CONFIRMED — cross-tenant read in VerificationController loyalty branch
`app/Http/Controllers/VerificationController.php:162`. The loyalty branch uses
`Contact::withoutGlobalScopes()->find($verification->subject_id)`, unlike its five
sibling branches (`:104,:114,:124,:136,:149`) which use scoped finds inside
`CurrentCompany::as($company)`. The returned Contact is never verified to belong
to `$verification->company_id` and is rendered on the public page (`:168`→`:64`).
Any token whose `company_id` and `subject_id` disagree (contact merge/import/
restore bug) becomes a public PII disclosure instead of a null. The scope is
exactly the defence that should catch that; there is no reason this line differs
from its neighbours.

### 4.4 SUSPECTED — SyncEngine replay check spans all tenants with an attacker-chosen id
`app/Services/SyncEngine.php:79`. `SyncReceipt::query()->withoutGlobalScopes()
->find($id)` where `$id` is `$envelope['id']` (client-generated, `:71`). A device
from company A submitting an id matching company B's receipt gets back B's
`assigned_number` and `server_version` (read leak); or A pre-registers ids and B's
legitimate colliding envelope is swallowed as `duplicate` and never retried (write
denial). Scope to the current company. Rest of SyncEngine is well-behaved: `write()`
(`:121`) scoped find, `:145` forces company_id, `sanitise()` (`:293-319`) strips
id/company_id/number/content_hash.

### 4.5 SUSPECTED — device-supplied foreign keys not checked for tenancy
`app/Services/SyncEngine.php:143`. `forceFill(sanitise(...))` blocks `company_id`
but not relational `*_id` columns (e.g. `contact_id` on a Document). A device can
write a dangling cross-tenant reference. No disclosure today (scoped relation reads
resolve to null) but any future `withoutGlobalScopes()` read of that relation
becomes a leak.

### 4.6 SUSPECTED — CurrentCompany::as(null) fatals on an orphaned token
`CurrentCompany::as()` is typed `Company $company`. `VerificationController:49-51`,
`DocumentShareController:44-46`, `SignatureController:36-38,:89-91,:122-124` pass an
unchecked `Company::find()`. A hard-deleted company with a live token turns a
public URL into a TypeError → 500. Contrast `ShipmentTrackingController:38-42` and
`TriagePublicController:166-170`, which `abort(404)` on null.

### 4.7 SUSPECTED — signing and share links ignore company suspension
`SignatureController` and `DocumentShareController` are the only public controllers
not using `AbortsForSuspendedCompany` (used by Form/Vacancy/Event/Shipment/Triage/
Profile). A business suspended for abuse keeps executing legally-binding signatures
and serving `/share/{token}?format=pdf`. The trait docstring only excuses
verification. Worth confirming intentional.

### 4.8 Cleared — route-model binding, API tree, public writes
No `resolveRouteBinding`/`Route::bind`/`withoutScopedBindings` anywhere; default
binding applies CompanyScope so cross-company ids 404. `app/Http/Controllers/Api/*`
has no unscoped lookups (`TicketController.php:108` scoped; `:129` re-verifies
`event_id`). Public writes force `company_id` from the token'd record
(`FormPublicController:166-170`, `TicketSeller:76,88`, `RecruitmentPipeline:75+`).
Admin `acrossAllCompanies()` uses sit behind the platform-admin guard.

---

## 5. VAT / rounding / XAF

### 5.1 Cleared (with one SUSPECTED drift) — Vat::compute is round-per-line-then-sum, XAF-aware
`app/Support/Vat.php`. `decimalsFor()` returns 0 for XAF/XOF (`:ZERO_DECIMAL`);
lines are rounded to the currency minor unit first and totals summed from the
printed figures (`:subtotal/taxTotal/total`), so the invoice adds up in hand.
Inclusive extraction takes tax as the remainder so `net + tax == gross` exactly.
Discount reduces the taxable base before the rate. This is correct.
SUSPECTED residue: the ledger split in `RecordsBusinessEvents::revenueByAccount`
(`app/Services/Accounting/RecordsBusinessEvents.php:140-182`) re-derives the
revenue split from `line_total` and reconciles drift onto the last account
against `document->subtotal`; the receivable line uses `document->total` and the
tax line uses `document->tax_total`. Because all three legs read the document's
own stored rounded figures, the entry balances at Ledger's 0.005 tolerance — no
confirmed unbalanced posting. Flagged only as the place any future divergence
between stored totals and recomputed splits would surface.

### 5.2 SUSPECTED — partial credit-note VAT re-derivation can drift one minor unit
`app/Services/DocumentConverter.php:136-138`. A partial credit computes
`$tax = round($amount * (invoice.tax_total / invoice.total), 2)` and
`$net = round($amount - $tax, 2)`. For a mixed-rate or discounted invoice the
effective ratio is an average, so the credited tax is proportional rather than
line-accurate; aggregate credits across several partials can differ from the
original tax by a franc/centime. The over-credit *total* is still capped by
`creditableAmount()` (gross), so this is a TVA-reclaim accuracy issue, not an
over-credit. Instalment splitting (`app/Services/Insurance/Policies.php:424-433`)
is correct: floor the slice, remainder on the first, XAF-aware — sums exactly.

---

## 6. Midnight-trap date bounds

### 6.1 Cleared — the named report sites normalise to startOfDay/endOfDay
Column types confirmed from migrations: `payments.received_at`,
`receipts.issued_at`, `documents.issued_at`, `refunds.refunded_at` are DATETIME;
`documents.issue_date`, `expenses.issue_date` are DATE. Checked:
`PrintController.php:103` (passes Carbon objects, `endOfDay` at `:89`; `toDateString`
only on the DATE column), `Kpis.php:96,160` (`startOfDay`/`endOfDay` at `:87-88`),
`Statement.php:132,145` (`:64-65`), `SupplierAccount.php:121` (`:64-65`),
`Reports/Index.php:92,166` (`window()` returns start/endOf* bounds). None drops a
day.

### 6.2 SUSPECTED — Reports\Index timezone skew (wall-clock bounds bound against UTC storage)
`app/Livewire/Reports/Index.php:32`. Bounds are built in the company timezone
(`CarbonImmutable::now($company->timezone)`), but `config/app.php` stores
`received_at` in UTC and Eloquent binds a Carbon by its wall-clock string without
converting. For UTC+1 the last hour of a month lands in the wrong month and the
first hour is double-counted. `Kpis` does not apply a company timezone, so the KPI
card and this report can disagree despite the "same query" comment at Kpis.php:90-91.
Verify against the Payment model casts / connection timezone before acting.

---

## Ten most serious (one line each)

1. CONFIRMED — Ledger idempotency is a lockless read with no unique index; concurrent replays double the books. `Ledger.php:81-97`, migration `:58`.
2. CONFIRMED — Voiding a paid document: `amount_paid` guard unlocked/outside txn → voided doc with real payment against it (money lost). `DocumentConverter.php:255-269`.
3. CONFIRMED — DocumentIssuer status guard outside txn + StockLedger::recordSale has no idempotency guard → stock decremented twice. `DocumentIssuer.php:37-51`.
4. CONFIRMED — Unguarded double-reversal in void paths; `entryFor()` always returns the original, so a replayed void reverses the entry twice. `ExpenseRecorder.php:168-172`, `PayrollRunner.php:258-264`, `Stocktaker.php:240-245`.
5. CONFIRMED — Fulfilment::deliver status checked outside txn, cached `quantity_reserved` trusted → same goods ship twice, stock negative. `Fulfilment.php:336-363`.
6. CONFIRMED — Reservation availability is an unlocked SUM → two confirms oversell the same item. `Fulfilment.php:207-215`, `StockReservations.php:134-137`.
7. CONFIRMED — Credit-note creditable-amount check spans the txn boundary unlocked → invoice credited beyond its total. `DocumentConverter.php:122-139`.
8. CONFIRMED — Reconciler marks a bank line `matched` with `journal_entry_id = null` after a swallowed posting failure → money vanishes from the books. `Reconciler.php:334-358`.
9. CONFIRMED — VerificationController loyalty branch drops the company scope → cross-tenant Contact PII on a public page. `VerificationController.php:162`.
10. CONFIRMED — SetCurrentCompany leaves a stale singleton on the guest branch; EventPublicController never sets company context → public breakage now, cross-tenant read under a shared-container worker. `SetCurrentCompany.php:26-30`, `EventPublicController.php`.
