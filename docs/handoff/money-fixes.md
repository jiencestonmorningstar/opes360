# Money fixes — handoff

Four confirmed money bugs, fixed test-first. New coverage lives in
`tests/Feature/MoneyFixesTest.php` (12 tests); two existing tests in
`CreditNoteTest`/`DebitNoteTest` were updated because they asserted the
fractional-franc behaviour being removed.

## P0 — discounted invoices never posted

**What.** `RecordsBusinessEvents::recordIssuedDocument()` debited 411 the
discounted total but credited revenue the *pre-discount* subtotal plus tax.
The entry was out of balance by exactly the discount, `Ledger::post()` refused
it, and `recordQuietly()` (by design) swallowed the refusal into a log line.
Every discounted invoice — API `discount_percent`, the composer's VIP tier,
recurring schedules with a locked discount — billed the customer with **no**
journal entry: no revenue, no TVA collectée, no 411.

**Fix.** The entry now carries the discount explicitly:

```
411 Clients                    107 325 D
709 Rabais, remises accordés    10 000 D
701 Ventes                               C 100 000
443 État, TVA facturée                   C  17 325
```

**The account, and why 709.** SYSCOHADA (révisé, AUDCIF 2017) records
commercial reductions granted on sales in **709 «Rabais, remises et
ristournes accordés»** — a contra-revenue account, debit-normal despite
sitting in class 7. We chose it over the two alternatives:

- *Crediting revenue net* balances but hides the discounting: the compte de
  résultat shows a smaller sales line and the owner can never read off how
  much margin the tiers/discounts are giving away.
- *673 «Escomptes accordés»* is a **financial** charge — early-settlement
  discount for paying ahead of terms. Our discounts are commercial (VIP tier,
  negotiated percentage at invoicing time), which is precisely what the RRR
  family exists for. Using 673 would misclassify a commercial reduction as a
  financing cost.

709 was added to `ChartOfAccounts::ROLES` (role `discounts_granted`) so new
and re-seeded charts carry it, with a `normalBalanceFor()` special case making
it debit-normal. **Fallback:** a company whose chart predates 709 gets revenue
credited net of the discount instead — a balanced (if less expressive) entry
beats the silent posting failure this replaces. Re-running "seed chart" adds
709 without touching an accountant's edits.

A regression test also pins `recordQuietly()`: failures still return null and
still log a warning.

## P1 — credit/debit notes ignored XAF's zero decimals

**What.** `DocumentConverter::creditNote()` and `DebitNotes::raise()/split()`
hard-coded `round(..., 2)`, so an XAF note could show 6 455,56 F — a fraction
of a franc that does not exist and can never be settled.

**Fix.** Both now round with `Vat::decimalsFor($currency)` like the rest of
the platform (note currency = the invoice's, or the company's for standalone
debit notes). Additionally, the **last credit slice absorbs the TVA residue**:
when a credit note takes the full remaining creditable amount, its tax is
`invoice.tax_total − tax already credited` rather than the proportional share,
so an XAF invoice credited in pieces ends with exactly zero residual TVA
(test: 119 250 credited as 40 000 + 40 000 + 39 250 reclaims exactly 19 250).

Two `DebitNoteTest` cases and one `CreditNoteTest` case previously asserted
centime figures (96.25 TVA etc.) on XAF documents; they were updated to the
whole-franc figures — the old assertions encoded the bug.

## P1 — over-credit race (TOCTOU)

**What.** `creditableAmount()` was checked before the transaction with no
lock: two concurrent credits could both read the same remaining amount and
together credit more than was ever charged. The same window existed in
`convert()` for two racing full-value conversions.

**Fix.** Mirroring `PaymentRecorder`'s pattern, `creditNote()` now re-reads
the invoice with `lockForUpdate()` *inside* `DB::transaction()` and checks the
remaining amount there; `convert()` re-checks `creditedTotal()` under the same
lock when the target is a credit note. The pre-transaction check in
`convert()` stays for the fast, friendly error.

## P2 — API stored gross unit_price where the UI stores net

**What.** `Api\DocumentController::store()` wrote the caller's raw
`unit_price` onto the line, while the composer (`Livewire\Documents\Create`)
always stores `unit_net`. For a TTC-keyed company, the same line meant two
different things depending on which door it came through.

**Fix.** The API now stores `$vat['lines'][$index]['unit_net']`, matching the
UI. **Pre-existing rows keep their stored values** — no back-fill; line totals
and document totals were always computed from the Vat pass and are unaffected.

## Files touched

- `app/Services/Accounting/RecordsBusinessEvents.php` — discount posting
- `app/Support/Accounting/ChartOfAccounts.php` — 709 role + normal balance
- `app/Services/DocumentConverter.php` — XAF decimals, residue absorption, lock
- `app/Services/DebitNotes.php` — XAF decimals
- `app/Http/Controllers/Api/DocumentController.php` — net unit_price
- `tests/Feature/MoneyFixesTest.php` (new), `CreditNoteTest.php`,
  `DebitNoteTest.php` (whole-franc expectations)

Sweep `php artisan test --filter="Vat|Document|Ledger|Credit|Debit|Recurring|Vip|Books|Accounting"`:
842 passed. Pint: passed.
