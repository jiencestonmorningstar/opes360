# OPES360 — Key Technical Decisions

Short records of the decisions that shape the build, each with the trade-off stated honestly. Anything marked
**Open** needs a call from you before the relevant phase starts.

---

## 1. Laravel 12 monolith with Livewire 3 — not an SPA + API

**Decision:** server-rendered Blade + Livewire 3 + Alpine, with a narrow JSON API used only by the offline
sync engine.

**Why:** the product is document-heavy and form-heavy, which Livewire handles well with far less code than a
separate SPA. A single deployable also keeps costs down for the target market.

**Trade-off:** Livewire is chatty over poor networks, which is exactly the condition our users are in. The
mitigation is that offline-critical screens (document creation, receipts, catalogue) are built as
**local-first Alpine components reading and writing IndexedDB directly**, with Livewire used for the
connected-only parts of the app (settings, reports, admin). This split is a real constraint on Phases 5–7 and
needs to be respected in code review, not just in this document.

---

## 2. Single database, row-level tenancy

**Decision:** one MySQL database; every business table carries `company_id`; a global Eloquent scope plus
policy checks enforce isolation. The spec's "separate MySQL records" is read as row-level separation, not
database-per-tenant.

**Why:** database-per-tenant multiplies migration, backup and connection-pool complexity by the number of
customers, and buys isolation we can get more cheaply. Cross-company reporting for a single owner (a real
requirement of Module 14) becomes trivial rather than painful.

**Trade-off:** a scoping bug leaks data across tenants. Mitigations: the global scope is applied in a base
model, not per-model; an automated test asserts every business table has `company_id` and every model extends
the base; a CI check fails on raw queries against business tables.

**Revisit if:** a single enterprise customer requires physical data isolation for compliance.

---

## 3. ULIDs for anything creatable offline

**Decision:** ULID primary keys on companies, customers, products, documents, payments, receipts and their
line items. Auto-increment integers on purely server-side tables (permissions, settings, jobs).

**Why:** offline devices must generate IDs that will never collide on sync. ULIDs sort chronologically,
unlike UUIDv4, which keeps index locality reasonable in InnoDB.

**Trade-off:** 26-character keys are larger than integers, costing index size and some join performance. At
the expected data scale this is not a concern; at 100M+ rows per table it would be.

**Note:** document *numbers* shown to users are entirely separate from ULIDs — see `offline-sync.md`.

---

## 4. Two PDF paths: headless Chromium on the server, browser print offline

**Decision:**
- **Online / archival:** headless Chromium (Browsershot) renders HTML+CSS with `@page` rules to PDF in a
  queued job. Used for stationery, emailed documents and anything stored.
- **Offline:** the same templates render in the user's own browser and go through the native print dialog. No
  server round-trip, no PDF library shipped to the client.

**Why:** DomPDF and similar PHP libraries cannot do the typography, bleed and A3 layout this product
promises. Chromium's print engine is the same engine the templates were designed in, so preview matches
output. Sharing templates between the two paths means one set of templates, not two.

**Trade-off:** Chromium is a heavy dependency (memory, container size, cold start). It runs in a queue worker,
not in the request path. Offline PDFs depend on the user's print dialog, which varies by platform — the
archival PDF is regenerated server-side on sync so the stored copy is always canonical.

**Open:** CMYK. Chromium outputs RGB. Most digital print shops accept RGB PDFs; offset printers may not. If
CMYK is required, a Ghostscript post-processing step is needed — decide by end of Phase 3.

---

## 5. Logo generation: parametric SVG templates, AI as the selector

**Decision:** logos are composed server-side from a curated library of SVG templates (layout × icon ×
typeface × palette). The AI layer proposes names, taglines, palettes and icon/font pairings, and picks and
configures templates — it does not draw the artwork.

**Why:** the spec requires **SVG export and transparent background at print resolution**. Raster diffusion
models produce PNGs with artefacts, inconsistent stroke weights and no clean vector path; auto-tracing them
gives bloated, unusable SVGs. A template composer produces genuinely clean vectors, is fast, is cheap, is
deterministic, and cannot generate trademark-infringing or offensive output.

**Trade-off:** less novelty than "the AI invented my logo." Output quality is bounded by the template
library, which makes the library a **design investment, not an engineering one** — budget real designer time
for it in Phase 2. This is the single biggest quality risk in R1.

**Possible enhancement (not committed):** a diffusion model generating *icon marks only*, professionally
vectorised and added to the icon library as a curated batch. Keeps the pipeline clean while widening variety.

---

## 6. Issued documents are immutable

**Decision:** once a document leaves draft, its content is frozen. Corrections happen through void + reissue,
or through credit/debit notes. There is no edit path on an issued invoice.

**Why:** this is standard accounting practice and good compliance hygiene, *and* it is the single most
effective simplification available to the sync engine. Concurrent edits to the same issued document simply
cannot happen, so most conflict-resolution machinery is unnecessary. Tamper detection (a content hash stored
at issue) becomes trivial to implement and verify.

**Trade-off:** users will ask to edit issued invoices, because other tools let them. This needs to be handled
in UX — make void-and-reissue fast and obvious rather than making the rule negotiable.

---

## 7. Thermal printing — platform reality

**Situation:** the spec requires 58mm and 80mm thermal receipts from a PWA.

- **Android / Chromium:** Web Bluetooth and WebUSB are available. We can drive ESC/POS printers directly from
  the browser. This works well.
- **iOS Safari:** neither API exists, and Apple has shown no intent to ship them. **There is no way to drive a
  thermal printer directly from a PWA on iPhone.**

**Options for iOS:**
1. Render the receipt to an image and pass it to the printer vendor's own app via the share sheet. Works
   today, but it is a clunky two-app flow.
2. AirPrint-compatible receipt printers via the standard print dialog. Clean, but constrains hardware choice
   and costs more.
3. A thin native iOS companion app purely for printing. Contradicts the app-store-free positioning.

**Recommendation:** option 1 as the default, option 2 documented as the premium hardware path, and set
expectations in marketing rather than discovering this at launch.

**Open:** your call, needed before Phase 7.

---

## 8. QR and AR share one verification token

**Decision:** one immutable public token per verifiable entity. The QR encodes `https://opes360.com/v/{token}`;
the AR marker resolves to the same token. Two renderers, one identity and one trust model.

**Why:** halves the surface area, guarantees QR and AR can never disagree about a document's status, and means
Phase 8 adds a renderer rather than a parallel system. Full detail in `qr-ar.md`.

---

## 9. Redis for queues, cache and sessions

**Decision:** Redis from Phase 0. Queue workers via Horizon.

**Why:** PDF rendering, AI calls, sync fan-out and report aggregation all need real background processing.
Database queues would work at first and become a bottleneck at exactly the wrong moment.

**Trade-off:** one more service to run. Acceptable — the database-queue fallback is a config change if a
deployment target genuinely cannot provide Redis.

---

## 10. Encryption and sensitive data

**Decision:** application-level encryption on tax identifiers, bank details and payment credentials; TLS
everywhere; secrets in the environment, never in the database.

**Trade-off:** encrypted columns cannot be searched or indexed. Where lookup is needed (e.g. finding a
customer by tax ID), store a blind index — a keyed hash of the normalised value — alongside the ciphertext.

---

## 11. Statutory rates live in config, and a payroll run keeps its own copy

**Decision:** every Cameroonian payroll figure — the CNPS ceiling, the IRPP bands, the TDL and RAV scales,
the CAC, CFC and FNE rates — is read from `config/payroll.php`. `PayrollCalculator` holds no numbers of its
own; it is constructed with a rates array. When a run is approved, the rates in force are copied onto the
run, and the payslips it produced store their computed figures rather than recomputing on read.

**Why:** these numbers are revised by finance act. A rate hard-coded in a service is a rate nobody can find
in January, and payroll arithmetic that is quietly a year out of date produces declarations the business
signs and is liable for. Equally, once a month is approved its payslips exist outside this database — the
employee has a copy, the CNPS has a declaration built from it — so recomputing them the day the config is
edited would rewrite documents already in other people's hands.

**Trade-off:** the same figure is stored in three places (the payslip's columns, its lines, and the run's
rates snapshot) rather than derived once. That redundancy is the point: it is what makes a payslip from two
years ago still explainable line by line. The cost is that a correction is a void-and-rerun, never an edit.

**What is deliberately *not* in config:** a business's CNPS risk group and family-allowance regime. Those
differ per business rather than per country, so they sit on the companies table — editing a shared config
file for one business would change the employer's bill for all of them.

---

## 12. An employee is not a user

**Decision:** `employees` is its own table with a nullable `user_id`, and pay history lives on
`employment_contracts` rather than on the employee.

**Why:** a small business here has a driver, two shop assistants and a night watchman. None of them will
ever log in and several have no email address, so modelling an employee as a `users` row would mean
inventing credentials for people who do not want them, and would tie the payroll to the seat count.
Separating contracts is what makes June's payslip still say June's salary after a July promotion: a run
reads the contract in force on its own period, not the latest one.

**Trade-off:** two records to keep in step for the minority of staff who do also have a login, and a raise
is a new contract rather than an edited field — which reads as ceremony until the first time somebody has
to produce last year's payroll.

---

## 13. Modules are switchable per business, enforced in one place

**Decision:** every module beyond the account itself is listed in `config/modules.php` and can be switched
off per company. Enforcement is a single check in `AuthServiceProvider`'s `Gate::before`, which denies every
ability belonging to a disabled module.

**Why one place:** the navigation, the routes, the quick actions and the Blade `@can` blocks already ask the
same gate. Filtering there means they cannot drift — a module switched off goes quiet everywhere at once,
and adding a module is an entry in one file rather than four templates remembered correctly. The alternative,
a `module:` route middleware plus a nav flag plus a view condition, has three places to forget.

Two lookups are needed, not one. A page-level check asks `sales.view`, so the ability names the module; a
policy check asks `view` with a `Document`, so only the model does. Mapping abilities alone would leave
every detail page reachable by its direct URL, which is why `models` exists in the catalogue.

**Storage:** `companies.modules` holds only the *departures* from the defaults. Writing out the enabled set
in full would mean a module added in a later release is missing for every business whose stored list
predates it — a bug nobody notices until a customer asks where the new feature is.

**Trade-off:** a business can switch off a module whose data other modules read. Dependencies (`requires`)
handle the cases where that would break something outright — payroll without HR has no contracts to read —
and cascade at read time rather than at write time, so re-enabling the parent restores the child exactly as
it was. Switching off never deletes: the screens go quiet and the data waits.

**Not switchable:** the business record, its users, its devices and its settings are the account, not a
feature of it, so they belong to no module and cannot be turned off by accident. The secretariat programme
is listed but fixed on, because a secretariat that switched it off would have signed up for nothing.

---

## 14. Stock reaches the books through a count, not through every sale

**Decision:** purchases are charged to 601 when they happen; the shelf is drawn down by a perpetual
movement ledger; and the two are married by a **stocktake**, which values what is left at weighted average
cost, carries it to 31 Marchandises and posts the change to 6031 Variation des stocks. There is no
cost-of-goods entry on the sale itself.

**Why:** this is SYSCOHADA's *inventaire intermittent*, the presentation the compte de résultat is laid out
for — "achats moins variation des stocks" is a line on the form. It is also the only method that works for
the businesses this software is for. Posting cost of goods at the moment of sale (*inventaire permanent*)
needs a reliable unit cost for every item on every invoice; a shop that has never typed a cost price would
get an income statement full of zero-cost sales and a gross margin of 100%, which is worse than no figure at
all. A count needs a cost only for what is actually still there, and the screen names the items it could not
price rather than quietly valuing them at nothing.

**Why weighted average:** CUMP de fin de période, one of the two methods the AUDCIF allows. It needs no lot
tracking and gives the same answer whichever crate the shopkeeper reached into — FIFO would need both.
Costs are read off the movements rather than off the item, because `items.cost` is what the *next* one is
expected to cost, and building the books on it would silently restate history the day a supplier's price
changed.

**What changed on the sale side regardless:** issuing a document now writes a negative stock movement for
every tracked product line, and voiding one writes the opposite. Before this, stock on hand ignored every
invoice ever issued — it was fed only by opening balances, hand adjustments and transfers — so the figure
was right only for a business that never sold anything. Voiding also now reverses the sale's journal entry,
which it never did: the books were claiming revenue and a receivable for a sale the business had publicly
cancelled.

**Trade-off:** between counts, account 31 is stale and the income statement overstates cost by whatever has
been bought and not yet consumed. That is inherent to intermittent inventory and is why the valuation screen
puts the shelf figure and the book figure side by side with the gap between them as its headline: the drift
is visible and one action closes it. A business that wants the books right monthly counts monthly.

**Uncounted is not zero.** A blank box on a count sheet stays blank and the line keeps its book quantity.
Reading it as an empty shelf would write off everything whoever was counting did not reach before closing
time, which is the single most expensive way this feature could be wrong.

---

## 15. A customer's balance is recomputed, never nudged

**Decision:** `contacts.balance` stays a stored column, but every event that could change it calls
`Contact::recomputeBalance()`, which sums the customer's issued documents — receivables positive, credit
notes negative. Nothing increments or decrements it.

**Why the column stays:** the customers list sorts and pages on "who owes the most". A computed value cannot
be an `ORDER BY` on a paginated query without a subquery on every row, and this list is one of the first
screens a business opens in the morning.

**Why not incremental:** it had already failed. Payments decremented the column and voids decremented it and
*nothing anywhere incremented it*, so a customer invoiced 1 000 who paid 400 was stored as owing minus 400.
The "owing" badge renders only above zero, so it silently never appeared; the list sorts by balance
descending, so the worst debtors sorted last. That is the failure mode of incremental maintenance in
general — one missing hook is permanent and invisible, because nothing ever recomputes to contradict it.
Recomputing from the documents is idempotent, self-healing, and costs one small indexed query per event.

**Consequence for credit notes:** they subtract, which is the whole of "allocating a credit note against the
customer's account". No allocation table, no matching step. An issued credit note is money the business has
said in writing it is no longer owed, and the customer's account says so from that moment. The invoice it
came from is untouched and still reads what it read when it was printed — which is the point of a credit
note as opposed to an edit.

**Guard:** an invoice cannot be credited beyond its own total. Without it, the "Convert to Credit Note"
button was a full-value credit note every time it was pressed, and pressing it twice would have recorded the
business as owing its customer the entire amount of a bill it had raised correctly.
