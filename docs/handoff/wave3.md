# Wave 3 — production readiness

What the production audit (`docs/audits/production.md`) flagged, and what was
done about each item. All targeted suites pass
(`php artisan test --filter="Upload|Webhook|Deal|Lead|Dashboard|Sla|Service"`,
258 tests) and pint is clean.

## 1. CLAMAV_SOCKET survives `config:cache`

`UploadGate::socket()` was the only raw `env()` call in `app/` — which reads
`null` once `php artisan config:cache` runs (every documented deploy), silently
disabling virus scanning exactly where it was configured. The key now lives at
`config('services.clamav.socket')` (`config/services.php`): services.php, not a
new file, because clamd is precisely what that file holds — a third-party
service the app talks to. `.env` usage is unchanged; `docs/handoff/upload-gate.md`
updated. Test: `UploadGateTest::test_the_socket_is_read_from_config_so_it_survives_config_cache`.

## 2. `.env.example` completeness

Added, each with a comment stating the failure mode of leaving it unset:
`CLAMAV_SOCKET`, `CONTACT_RECIPIENT`, `CSP_ENABLED`, `CSP_REPORT_ONLY`,
`OPES_DEMO_LOGINS=false` (with a warning — the config default is *on*, and it
must be off in production), and a commented `ORANGE_MONEY_OAUTH_PATH`.

## 3. Branded error pages

`resources/views/errors/{404,419,500,503}.blade.php`, all rendering through one
self-contained skeleton (`errors/partials/page.blade.php`): inline CSS, inline
SVG icons, system fonts, no Vite, no session, no components — because a 500
page that needs the asset pipeline is a 500 page that can 500. The palette
hand-copies the app's tokens so it matches `errors/403.blade.php` visually
without sharing its dependencies. 419 — the page every expired Livewire session
hits — says plainly that the session expired and a refresh fixes it.

## 4. Webhook retries under the sync queue

`DeliverWebhook::giveUpOrRetry()` now checks `config('queue.default')`: on
`sync` — where dispatch delays are ignored and the self-dispatch chain would run
all five HTTP attempts inline in the originating request — the delivery fails
after its first attempt, with a log line and a note on the delivery telling the
operator that a queue worker enables retries. On a real driver the hand-rolled
backoff schedule is unchanged. Tests updated/added in `WebhookTest`.

## 5. Boards capped

- **Deals** (`Livewire\Deals\Index`): counts and value totals come from one
  grouped aggregate; each column loads at most `COLUMN_LIMIT` (50) cards; the
  column header shows the true count and a "+N more — search to find them"
  note when capped. Stage moves (the select control) are untouched.
- **Leads** (`Livewire\Leads\Index`): the register shows the newest
  `LIST_LIMIT` (100), with a "Showing X of Y" footer; the funnel count is a
  COUNT, not the loaded page.

Search narrows the *query* in both, so nothing capped is unreachable.

## 6. Dashboard

- The sales chart is built once and its total summed from the same collection
  (was two identical invoice queries per render).
- The low-stock counter is one SQL COUNT with a correlated
  `COALESCE(SUM(quantity))` subquery against the movement ledger — portable
  SQL, runs on MySQL and SQLite — instead of hydrating every tracked product
  and filtering in PHP.

## 7. SLA policy edits recompute open tickets

The self-declared largest gap in `docs/handoff/4.7-integration.md` (now updated).
Saving a policy's calendar **or** targets runs
`Livewire\Service\Policies::recompute()`: `SlaClock::apply()` over the policy's
unsettled tickets in chunks of 100. Settled tickets keep the deadlines they
were resolved under — a widened policy must not erase breaches already
reported. `pending_customer` tickets are included (paused, not finished).
Inline rather than queued, argued in the method comment: two indexed reads and
a write per ticket, and the common install runs a sync queue anyway; the
chunking makes a later move to a job a cut-paste. Test:
`ServiceScreensTest::test_changing_a_target_recomputes_open_tickets_but_not_settled_ones`.

## 8. Lazy-loading in production

`Model::preventLazyLoading()` is now on in every environment, but production
registers `handleLazyLoadingViolationUsing()` to log (model + relation) instead
of throwing — N+1 regressions surface in the logs as a one-line eager-load fix,
without turning a working page into a 500.

## Also touched in passing

`tests/Feature/Service/TriageTest.php` — the parallel `SetCurrentCompany`
hardening (guest requests clear the tenant singleton) left the triage tests
reading from no tenant after their public POSTs; the test now re-pins the
company after each post.
