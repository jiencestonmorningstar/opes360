# Walk-in triage — integration handoff

## What shipped

A public, mobile-first triage page behind the company's existing printed QR. A
customer on premise scans the code, picks what kind of problem they have, adds
a description, rates how urgent it feels, leaves name + phone, and gets the
ticket reference LARGE as their queue number plus how many open walk-ins are
ahead of them. Staff see the ticket arrive in the existing service queue with a
"Walked in" badge. No login, no new QR concept, no second desk.

- `app/Http/Controllers/TriagePublicController.php` — resolves the company from
  its `VerificationToken` (company-subject tokens only; a receipt/invoice token
  404s here), sets `CurrentCompany` exactly the way `VerificationController`
  does, and creates tickets **only** through `TicketDesk::open()` with
  `channel => 'walk_in'` — so numbering, the SLA clock and events all apply.
- `resources/views/public/triage.blade.php` / `triage-done.blade.php` — on the
  shared `x-layouts.public` shell, same register as the public form pages.
- `database/migrations/2026_09_12_000401_add_visitor_details_to_service_tickets.php`
  — adds nullable `visitor_name` / `visitor_phone` (see below).
- `app/Services/Service/TicketDesk.php` — one additive change: the two visitor
  columns joined the `open()` attribute whitelist.
- `resources/views/livewire/service/index.blade.php` — the queue did not show
  channel; walk-in rows now carry a small "Walked in" badge, and the Customer
  column falls back to `visitor_name` when there is no contact.
- `tests/Feature/Service/TriageTest.php` — written first; 12 tests, all green,
  alongside the full existing service suite (95 passed). Pint clean.

## Route lines to add (I may not edit routes/*)

Append to the public section of `routes/web.php`, next to the `/v/{token}`
and `/f/{token}` groups — public, throttled, **outside** the auth group:

```php
use App\Http\Controllers\TriagePublicController;

/*
 * Walk-in triage — the service desk's front door, behind the same company QR
 * as verification. Reads at the standard public throttle; the write tighter,
 * because every submission opens a ticket and starts an SLA clock.
 */
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/triage/{token}', [TriagePublicController::class, 'show'])->name('triage.show');
    Route::get('/triage/{token}/done', [TriagePublicController::class, 'done'])->name('triage.done');
});
Route::post('/triage/{token}', [TriagePublicController::class, 'submit'])
    ->middleware('throttle:6,1')->name('triage.submit');
```

`TriageTest::setUp()` registers exactly these lines (plus an explicit `web`
group, which `routes/web.php` gets implicitly), so the suite is the contract
they must satisfy. Until they land, `route('triage.*')` does not exist in
production — add them with this merge.

## Where the QR already renders

Nothing new was built. The company `VerificationToken` QR already renders on
the Stationery screen (`resources/views/livewire/business/stationery.blade.php`,
via `route('verification.qr', $token)`), on the printable stationery
(`resources/views/print/stationery.blade.php`) and on the business edit screen.
The triage URL is `/triage/{that same token}`; if the business wants a
dedicated "scan to get help" sign, print the existing QR — one scan of one code
is deliberate. (A future nicety: a `?to=triage` hint on `/v/`, but that is a
product call, not plumbing.)

## Priority cap — the reasoning

Customer urgency choices are their own words ("Whenever you get to it" /
"Today would be good" / "I need help right now") mapping to `low` / `normal` /
`high`. **`urgent` is unreachable from the public page** — it is validated
against a whitelist, so a forged POST carrying `urgency=urgent` is rejected,
not clamped. `urgent` is an SLA promise (30-minute response under the default
policy) and it is the business's to make; a public page that let visitors make
it would let anyone with the QR spend the desk's credibility. Staff escalate
in the queue as they always could — the "Walked in" badge in the queue notes
this on the staff side; the customer side never mentions priorities at all.

## Why visitor_name / visitor_phone columns

Walk-ins are usually existing customers, so the controller first matches the
phone (normalised to digits, last-8 suffix match against the `phones` JSON) to
a `Contact` and links `contact_id`. When nothing matches, the ticket carries
the visitor's own words in `visitor_name` / `visitor_phone` instead — an
unauthenticated page must never create `Contact` rows, or one bored visitor
fills the customer book with junk. Columns rather than description-stuffing so
the queue can display the name and staff can later promote the visitor to a
real Contact deliberately. Both stay null on every other channel and whenever
a contact matched.

## Abuse control

- **Per-IP**: `throttle:6,1` on the POST (tighter than form submits' 10/min —
  a ticket starts an SLA clock and lands on the board).
- **Per-token**: a `RateLimiter` key on the token id, 30 submissions/hour, in
  the controller — a crowd of IPs hammering one business's code is still one
  business's queue being flooded. Route throttles alone can't see this.
- **Input caps**: description ≤ 2000 chars; name ≤ 120; phone ≤ 40; category
  and urgency validated against fixed whitelists.
- **Module gate**: `Modules::enabled($company, 'service')` — a disabled desk
  renders a polite "not taking walk-in requests" page (200), never an error,
  and the POST refuses to create anything.
- **Token discipline**: only company-subject, unrevoked tokens resolve;
  suspended companies abort via the shared `AbortsForSuspendedCompany` trait;
  the company is always derived from the token, never from input.
- **Audit actor**: tickets are attributed to the company owner (fallback:
  first member) so `TicketDesk`'s event trail stays intact.
