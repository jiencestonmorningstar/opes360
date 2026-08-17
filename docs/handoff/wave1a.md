# Wave 1a handoff — dark machinery gets its UI

Scope: PLAN.md Wave 1, items 1 (ExpenseClaim UI), 2 (FiscalPeriods UI),
3 (Project lifecycle + tasks/milestones), 10 (sweepExpired check).

## 1. Expense claims screen

- `app/Livewire/Expenses/Claims.php` + `resources/views/livewire/expenses/claims.blade.php`.
- Route added: `/expenses/claims`, name **`expenses.claims`**, middleware
  `can:expenses.claim-view` (`routes/web.php`, next to `/expenses`).
- Create (multi-line, category → SYSCOHADA account via the service, per-line
  cost centre and TVA rate), submit to the shared WorkflowEngine, reimburse
  (amount/method/date/reference) gated `expenses.claim-reimburse`. All
  refusals from ExpenseClaimService surface as `claims` errors, never crashes.
- No approve button on this screen — the workflow inbox owns approval.
- **Nav link not added** (nav is owned by your layout work): the screen is
  reachable only by URL/route until a sidebar entry exists for
  `route('expenses.claims')`.

## 2. Fiscal periods tab

- Added a `periods` ("Exercices") tab to `app/Livewire/Accounting/Index.php`
  and its blade — honest home: the tab bar the books already live in.
- Create a year (+12 months), close/reopen period, close/reopen year, all
  gated `accounting.manage`; service refusals (e.g. reopening a period inside
  a closed year) surface as `periods` errors.
- FiscalPeriods close/reopen now have their first callers; Ledger::post's
  period lock is live.

## 3. Project lifecycle + tasks/milestones

- `app/Livewire/Projects/Index.php` (+ blade): `transition()` with an explicit
  transition table — planning→active/completed/cancelled,
  active⇄on_hold, active/on_hold→completed, completed/cancelled terminal.
  `archive()` kept as an alias for cancel.
- **Complete refuses while tasks are open** (decision: "completed" is a claim
  the task list must agree with; cancel is the escape hatch and deliberately
  does not check). Complete/cancel stamp `closed_on`.
- Per-project fold-out panel: add task (title/due/milestone), move task
  through todo/in_progress/blocked/done (done stamps `completed_at`),
  add/complete/reopen milestone. Milestone ownership is checked against the
  project before a task is filed under it. All mutations behind
  `authorize('update', $project)` + `@can` in the blade.

## 4. StockReservation::sweepExpired — NOT scheduled

`routes/console.php` has no entry for it and no artisan command wraps
`App\Services\Stock\StockReservations::sweepExpired()` (line 167). The method
is company-scoped-model territory, so a scheduled closure must bypass the
tenant scope. Suggested line for `routes/console.php` (not applied, per brief):

```php
// Tidy lapsed stock reservations into 'released'. Cosmetic — holding()
// already ignores them — so daily in the small hours is plenty.
Schedule::call(fn () => \App\Models\StockReservation::withoutGlobalScopes()
    ->where('status', \App\Models\StockReservation::STATUS_ACTIVE)
    ->whereNotNull('expires_at')
    ->where('expires_at', '<=', now())
    ->update(['status' => \App\Models\StockReservation::STATUS_RELEASED]))
    ->daily()->at('02:45');
```

(or wrap it in a small `opes:sweep-stock-reservations` command if you prefer
every schedule entry to be a named command).

## Tests

New: `tests/Feature/Expenses/ExpenseClaimScreenTest.php`,
`tests/Feature/Accounting/FiscalPeriodScreenTest.php`,
`tests/Feature/Projects/ProjectLifecycleTest.php`.

`php artisan test --filter="ExpenseClaim|Fiscal|Project|Claim"` — 117 passed
(586 assertions), including all pre-existing service tests. Pint clean.
