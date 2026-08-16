# Audit trail retention

Closes the gap §7.6 of `4.5-integration.md` named the largest thing that phase
did not solve: `activity_log` grew forever, and the observer expansion roughly
triples the rate. The policy lives in `app/Support/AuditRetention.php`; the
command is `app/Console/Commands/PruneAuditLog.php` (`opes:prune-audit`).

## The tiers and floors, with reasoning

One company setting (`companies.audit_retention_months`, null = 24). Each tier
keeps `max(setting, floor)` — the setting can lengthen any tier and can shorten
only the access tier below the defaults; no setting undercuts a floor.

| Tier | Rows | Floor | Why |
|---|---|---|---|
| Access | `event = accessed` | **12 months** | "Who read the payslip" matters for the dispute cycle around it, not for a decade. Twelve months means a question raised at the next annual review — the natural horizon for a personnel dispute — can still be answered. These are the rows that triple the table, so they go first. |
| General | every other write about ordinary subjects | **24 months** | "Who changed this and from what" must survive the year it happened plus the year it gets argued about. Two full annual cycles. |
| Financial | writes whose subject is a money model (`FINANCIAL_SUBJECTS`: documents, payments, receipts, refunds, payroll, expenses, bank accounts, journal entries, ledger accounts, tax rates, fiscal periods…), plus `exported` and `permission-changed` regardless of subject | **120 months (10 years)** | OHADA-tradition bookkeeping keeps accounting records ten years. That is stated as our design reasoning, not a legal citation — the point is that the trail explaining the books must not be shorter-lived than the books, or it goes missing exactly when an inspector asks. Exports and permission grants sit here because an export is data that left, and a grant is the act that made every other act possible. |
| Summary (`retention-pruned`) | the pruner's own rows | **never pruned** | Delete the record that a deletion happened and the trail can no longer account for its own gaps. |

Access rows *about* financial subjects still prune at the access floor — a read
is a read; it is the write history of money that gets ten years.

## The trail records its own trimming

Each run writes **one** `retention-pruned` row per company that lost anything
(none when nothing was pruned, and none under `--pretend` — a summary claiming
a pruning that did not happen would itself be a false record). Properties carry
the count, the three cutoff instants, the months setting in force, and
`run_by: schedule:opes:prune-audit`. Written through `Audit::record()` like
everything else — no second write path.

Changing the setting is itself audited for free: it is a `companies` update and
`Company` is an observed model.

## What legal hold coverage means in practice

If a `BusinessDocument` has `legal_hold = true` (the documents module's
`DocumentRetention` flag, soft-deleted documents included), **every**
`activity_log` row whose subject is that document is excluded from all three
tiers, whatever its age — reads included. Lift the hold and the rows age out on
the next run under the ordinary tiers. This mirrors `DocumentRetention`
exactly: the hold is absolute and has no override parameter. Rows *about other
records* are not pinned by a document hold; legal hold is modelled on documents
and that is the scope honoured.

## Mechanics

- Per-company operation, `Company::withTrashed()->cursor()` — a soft-deleted
  company's trail still ages out; a company's setting never touches a
  neighbour's rows (tested).
- Chunked deletes: select 1000 ids ordered by primary key, `whereIn` delete,
  repeat — never offset pagination (rows vanish under an offset), never one
  giant `DELETE` holding the table.
- Cutoffs are Carbon instants compared against `created_at` directly — no
  `toDateString()`, so no midnight truncation.
- Rows whose subject was deleted long ago prune normally by their own
  `created_at` (tested — `ActivityLog` has no `BelongsToCompany` scope and the
  pruner filters `company_id` by hand, first and unconditionally).
- `--pretend` counts per tier and deletes nothing.

## Settings surface

A third **Retention** tab on `app/Livewire/Audit/Governance.php` (the honest
home: audit retention is a governance decision, and the screen is already gated
`audit.govern` in both `mount()` and the save action). Number input, validated
`min:12 max:600`; the floors are printed next to it in plain language. The
floor is enforced twice — form validation and again in `AuditRetention` — so a
value smuggled past Livewire still cannot shorten what the pruner keeps.

## Schedule line for routes/console.php (I do not own that file — add this)

```php
/*
 * Trim the audit trail under each company's stated retention policy. Nightly,
 * in the housekeeping window; the command chunks its deletes and writes one
 * summary row per company into the trail it trimmed, so a run is itself on
 * record. See App\Support\AuditRetention for the tiers and floors.
 */
Schedule::command('opes:prune-audit')
    ->daily()
    ->at('03:45')
    ->withoutOverlapping();
```

## Files

- `app/Support/AuditRetention.php` — policy + pruner (new)
- `app/Console/Commands/PruneAuditLog.php` — `opes:prune-audit {--pretend}` (new)
- `database/migrations/2026_09_13_000401_add_audit_retention_to_companies.php` — one nullable column (new)
- `app/Livewire/Audit/Governance.php` + `resources/views/livewire/audit/governance.blade.php` — Retention tab (additive edits)
- `tests/Feature/Audit/RetentionTest.php` — 11 tests

## Verification

```
php artisan test --filter="Audit|Retention" → 74 passed (201 assertions)
vendor/bin/pint → clean on all files above
```

## Unresolved

- **No archive.** Pruned rows are gone, not exported. A business wanting a
  cold-storage dump before deletion (the safest answer for the financial tier)
  needs an export step bolted in front of the delete; the tier query in
  `AuditRetention::prune()` is the place to hang it.
- **Legal hold scope is documents only.** A payroll dispute has no hold object
  to raise. If holds ever generalise beyond `BusinessDocument`, the exclusion
  in `prune()` is the one place to widen.
- **`FINANCIAL_SUBJECTS` is a hand-kept list** (strings, so a renamed model
  degrades to the general 24-month tier rather than erroring). A new money
  model should be added there the same way it gets a conflict rule in
  `SegregationOfDuties`. A test asserting the list against the observed-model
  registry would be worth adding once `AppServiceProvider` registers §5's
  models.
- Rows with `company_id = null` (system events: failed logins, company
  creation) are currently not pruned at all — there is no company whose policy
  covers them. Volume is tiny; a platform-level default sweep is a later
  decision.
