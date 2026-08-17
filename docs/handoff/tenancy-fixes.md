# Handoff — Tenancy / Public-Token Fixes (bugs.md §4)

All six tenancy/public findings from `docs/audits/bugs.md` §4 were worked
through: five fixed, one (4.5) refuted with evidence. Test-first: every fix has
a failing test written before the change, now green in
`tests/Feature/PublicTenancyTest.php`.

## Fixed

### 4.1 SetCurrentCompany now fails closed on the guest branch
`app/Http/Middleware/SetCurrentCompany.php` — the guest path now calls
`$this->current->set(null)` before `return $next($request)`. A guest request
can no longer inherit a tenant left in the container singleton by a previous
request (the Octane / shared-queue-worker leak). Under php-fpm this is a no-op.

Side effect on the test suite: the test process shares the singleton with the
kernel, so tests that make a guest request and then run scoped assertions must
re-pin the company. Adjusted: `tests/Feature/FormsTest.php` (a `post()`
override, same pattern `tests/Feature/Service/TriageTest.php` already uses) and
`tests/Feature/DemoClientEngagementTest.php` (explicit re-pins).

### 4.2 EventPublicController establishes company context from its token
`app/Http/Controllers/EventPublicController.php` — `show`, `embed`, `purchase`
and `tickets` now resolve everything inside
`CurrentCompany::as($event->company, ...)`. Before, `$event->ticketTypes`
(tenant-scoped `TicketType`) and `TicketSeller::sell` resolved against whatever
the singleton happened to hold — empty pages and failed purchases for a true
guest, another tenant's context under 4.1's stale-singleton condition. Views
are rendered eagerly (`response()->view`) inside the closure so nothing scoped
resolves after the context is popped.

### 4.3 VerificationController loyalty branch is scoped again
`app/Http/Controllers/VerificationController.php` — the loyalty branch now uses
`Contact::find(...)` (scoped, running inside `CurrentCompany::as($company)`)
instead of `Contact::withoutGlobalScopes()->find(...)`. A token whose
`company_id` and `subject_id` disagree now resolves to the "voided" verdict
instead of rendering another tenant's contact (name, phone, loyalty balance) on
a public page. Regression tests: `test_a_loyalty_token_never_renders_a_cross_tenant_contact`
and `test_a_loyalty_token_still_renders_its_own_contact`.

### 4.4 SyncEngine replay check scoped to the current company
`app/Services/SyncEngine.php` — the replay lookup is now
`SyncReceipt::query()->find($id)` (company-scoped; the sync endpoint always
runs authenticated with a current company). Before,
`withoutGlobalScopes()->find($id)` with a client-generated id let a device from
company A read company B's receipt (`assigned_number`, `server_version`) or
pre-register ids so B's colliding envelope was swallowed as `duplicate`.

### 4.6 Orphaned tokens 404 instead of fataling
`CurrentCompany::as()` is typed `Company $company`, so a hard-deleted company
with a live token was a TypeError → 500 on a public URL.
- `VerificationController::show` — null company now returns the branded
  "unknown" verdict view with 404 (same as an unknown token).
- `SignatureController` — new `companyFor()` helper aborts 404, used by
  `show`/`sign`/`decline`.
- `DocumentShareController::show` — `abort_if($company === null, 404)`.

### 4.7 Share and signing links now honour suspension
`SignatureController` and `DocumentShareController` were the only public
controllers not using `AbortsForSuspendedCompany`; both now abort 404 for a
suspended company. Judged a bug, not intent: the trait's docstring excuses only
*verification* (a customer checking paper already in their hands), and a
suspended business should not keep executing legally-binding signatures or
serving `/share/{token}?format=pdf`. Test:
`test_share_and_signing_links_go_dark_when_the_company_is_suspended`.

## Refuted / accepted as-is

### 4.5 Device-supplied foreign keys (SUSPECTED) — no fix, by judgement
`SyncEngine::sanitise()` strips every server-owned column including
`company_id`, and a cross-tenant `contact_id` written by a device is a dangling
reference only: every read path resolves relations through the company scope,
so it renders as null — no disclosure exists today, as the audit itself traced.
A generic per-model FK tenancy check would need a map of every relational
column per synced entity and belongs in the entity writers, not the transport.
The guard that matters — no future `withoutGlobalScopes()` relation read — is
exactly what 4.3's fix removed the last public instance of, and the
`PublicTenancyTest` sweep is the tripwire for any new one.

## Hardening sweep — tests/Feature/PublicTenancyTest.php

- Data-driven sweep over **every** public token family — `v`, `track`,
  `triage`, `jobs`, `share`, `sign`, `e`, `f`, and `invitations` (discovered by
  the sweep's own guard) — asserting company A's token never renders company
  B's name, twice: once as a clean guest, once with the singleton deliberately
  poisoned with company B (the long-lived-worker scenario).
- `test_the_sweep_covers_every_public_token_route` introspects the route table
  for `GET {segment}/{token}` routes and fails if any family is missing from
  the sweep — a future public route joins by default or blocks the build.
- Plus the per-finding regression tests described above (loyalty cross-tenant,
  guest singleton clear, event tickets/purchase with no and with a stale
  company, orphaned-token 404s, suspension).

## Verification

`php artisan test --filter="Tenancy|Public|Verification|Track|Triage|Share|Event|Form|Loyalty|Sync"`
— 408 passed (1776 assertions), 0 failed. Pint clean on all touched files.
