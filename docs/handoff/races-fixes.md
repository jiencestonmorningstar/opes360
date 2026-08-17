# Race / idempotency fixes — bugs.md findings 1–6 and 8

Fixes for the CONFIRMED race and replay bugs in `docs/audits/bugs.md`
(findings 1.1, 1.2, 2.1–2.4 status-guard halves, 3.3, and the top-ten items
1, 2, 3, 4, 5, 6, 8). Finding 7 (credit-note creditable check) was fixed in a
parallel change and is not touched here. The model copied throughout is
TicketSeller's lock-before-check: take the row FOR UPDATE inside the
transaction, then decide.

## What changed

### 1. Ledger idempotency (finding 1 / 1.1)
- `database/migrations/2026_08_17_000001_add_unique_source_to_journal_entries.php`
  — unique index `journal_entries_source_unique` on
  `(company_id, source_type, source_id)`. Reversals post with a null source,
  and NULLs never collide, so only sourced entries are constrained.
- `app/Services/Accounting/Ledger.php` — `entryFor()` gained a `$lock`
  parameter; `post()` calls it with `lock: true` inside the posting
  transaction, and wraps the insert in a catch for
  `UniqueConstraintViolationException` that re-reads and returns the winner's
  entry. A replay finds-or-refuses; it can no longer double-post.

### 2. Void of a paid document (finding 2 / 3.1)
- `app/Services/DocumentConverter.php::void()` — both guards (already-void,
  `amount_paid > 0`) now run inside the transaction on the row taken FOR
  UPDATE (lock, then `refresh()`), so a void racing a PaymentRecorder
  transaction (which locks the same row) sees the committed payment.

### 3. Double issue / stock decrement (finding 3 / 2.1)
- `app/Services/DocumentIssuer.php::issue()` — draft guard moved inside the
  transaction, on the locked row.
- `app/Services/Stock/StockLedger.php::move()` — `recordSale()` is now
  idempotent per document: existing `sale`/`credit` movements referencing the
  document mean it writes nothing. Safe as check-then-act because the caller
  holds the document row lock.

### 4. Double reversals (finding 4 / 3.3)
- `Ledger::reverse()` is idempotent per entry: the original entry row is
  locked as the mutex, and an existing reversal (an entry whose
  `reverses_entry_id` points at it) is returned instead of posting another.
- `ExpenseRecorder::void()`, `PayrollRunner::void()`, `Stocktaker::void()`
  and `Stocktaker::post()` each lock-and-refresh their record inside the
  transaction and refuse when the status has already flipped.

### 5. Fulfilment::deliver (finding 5 / 2.3)
- `app/Services/Orders/Fulfilment.php::deliver()` — order status and line
  quantities re-read under lock inside the transaction (order row locked,
  lines selected FOR UPDATE). `confirm()`, `reserveBackorders()` and
  `cancel()` got the same lock-before-check treatment, so a cancel can no
  longer race a deliver.

### 6. Reservation availability (finding 6 / 2.2)
- `app/Services/Stock/StockReservations.php::reserve()` and the availability
  reads in `Fulfilment::confirm()` / `reserveBackorders()` — the **item row**
  is taken FOR UPDATE before the `SUM()`s. "Available" is
  SUM(movements) − SUM(live reservations), aggregates with no lockable row of
  their own; the item row is the mutex that serialises the arithmetic, held
  to commit.

### 8. Reconciler swallow (finding 8 / 1.2)
- `app/Services/Banking/Reconciler.php::recordFromStatement()` — posts
  directly through `Ledger::post` instead of `recordQuietly`. A posting
  failure now propagates, the transaction rolls back, and the line stays
  unmatched — never `matched` with `journal_entry_id = null`.

## Tests

- `tests/Feature/LedgerRaceGuardsTest.php` — unique-index refusal, replay
  returns the original entry, double-reverse returns the original reversal,
  replayed expense void reverses once, failed posting leaves the statement
  line unmatched.
- `tests/Feature/DocumentRaceGuardsTest.php` — stale-model issue cannot
  decrement stock twice, `recordSale` replay writes nothing, a paid document
  refuses to void through a stale model.
- `tests/Feature/Orders/FulfilmentRaceGuardsTest.php` — stale confirm cannot
  double-reserve, stale deliver cannot double-ship, over-reserve refused.

True two-connection races cannot run inside one sqlite test transaction
(where `lockForUpdate` is also a no-op), so each test replays the race
single-threaded with a stale in-memory model — the exact state the losing
request holds — and asserts the locked re-read refuses it; the unique index
covers the interleaving no application check can see. The tests say so in
their docblocks.

## Verification

- `php artisan test --filter="Ledger|Document|Stock|Fulfilment|Reserv|Reconcil|Expense|Payroll|Books"`
  — 842 passed (2251 assertions), including the 11 new guards.
- Pint clean on every touched file.
- `php artisan opes:export-schema` re-run; the install schema now carries
  `journal_entries_source_unique`.

## Behaviour notes for callers

- Services that used to check status on the caller's in-memory model now
  lock and `refresh()` that same instance, so callers see the post-guard
  state; a stale replay gets a `RuntimeException` instead of silently
  doubling an effect.
- `ExpenseRecorder::void` and `PayrollRunner::void` now throw on an
  already-void record (previously the expense re-reversed and the run
  re-reversed silently).
- `Fulfilment::cancel` performs its delivered-quantity check inside the
  transaction; already-cancelled orders still return quietly.
