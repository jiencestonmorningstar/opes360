# Handoff — orders hardening (returns, quotations, credit control, platform sync)

The second pass over the sales-orders vertical: what the scan found missing,
what was built, and what was deliberately left to the integrator. Everything
here consumes existing machinery — the same non-negotiable as the first pass.

## The gap list (scan findings)

| # | Gap | Verdict | Where it landed |
|---|---|---|---|
| 1 | Quotation → order: `DocumentType::Quotation` existed, orders could not start from one | **Built** | `Fulfilment::fromQuotation()`, `sales_orders.source_document_id` |
| 1b | Deal → order | **Declined** (argued below) | — |
| 2 | Customer returns: no way to receive goods back or credit them | **Built** | `App\Services\Orders\Returns`, `delivery_note_lines.quantity_returned` |
| 3 | Credit control: `contacts.credit_limit` existed (CRM migration + cast + API resource) and *nothing read it* | **Built** | `Fulfilment::confirm()` blocks, named override, `sales_orders.credit_override_reason` |
| 4 | Pick from location: `sales_orders.stock_location_id` existed, nothing ever set it | **Built** | Location picker on the draft form when `stock_locations` is on |
| 5 | Delivery note PDF: `?format=pdf` worked on every print endpoint except this one | **Built** | `DeliveryNotePrintController` via `App\Support\Pdf` |
| 6 | Global search: SalesOrder + DeliveryNote absent from `GlobalSearch::sources()` | **Built** | Additive entries, ability `orders.view` |
| 7 | Domain events: no `orders` module in `DomainEvents::CATALOGUE` | **Built** | `order.confirmed/delivered/invoiced/return.recorded`, emitted from `Fulfilment`/`Returns` |
| 8 | Executive "short units" alarm | **Declined** (argued below) | proposed snippet below |
| 9 | Audit: SalesOrder/DeliveryNote not observed by `AuditObserver` | **Integrator** (list below) | — |

## What was built, in one pass each

### 1. Quotation → order — `Fulfilment::fromQuotation(Document $q, ?User $actor)`

An accepted (issued or accepted, never draft/void) quotation becomes a draft
sales order carrying its **item-bearing** lines and their prices, linked back
through `sales_orders.source_document_id`. Rules:

- Only lines with an `item_id` cross over — an order line promises a catalogue
  item, and a free-text quotation line has nothing to reserve. A quotation
  with *no* item-bearing line is refused with a message that says so.
- The price carried is the quotation line's `unit_price`, not today's
  catalogue price: the customer accepted a figure, and the order keeps it.
- One order per quotation — a second `fromQuotation` on the same document is
  refused (same doctrine as `DocumentConverter::canConvert`).
- The quotation is marked `Accepted`, exactly as `DocumentConverter::convert`
  does when a quotation becomes an invoice.

**Deal → order declined.** A won deal already converts to an invoice through
`DealPipeline` (a single value line, no items, no quantities). An order is a
promise of *catalogue items in quantities*; a deal carries neither, so a
conversion would have to invent both. The honest path a business follows
today: deal → quotation (items get named there) → order. Nothing built.

### 2. Customer returns — `App\Services\Orders\Returns::record()`

`record(DeliveryNote $note, array $quantities /* line id → qty */, User
$actor, string $reason)`; refusals throw `RuntimeException` like everything
else in the vertical.

- **Refuses returning more than was delivered** — per delivery-note line,
  `quantity − quantity_returned` is the ceiling, and a void note is refused
  outright.
- Stock comes back through the **existing ledger**: ordinary `StockMovement`
  rows, `reason = 'return'`, positive quantity, referenced to the
  DeliveryNote, at the note's own location. `unit_cost` stays null (the
  StockLedger stance: cost is a question about the stock, not the sale).
- Money follows the goods **through the existing credit-note path**:
  - The **uninvoiced** part of the return simply reduces the order line's
    `quantity_delivered`, so the next `invoice()` never bills goods that came
    back. No document exists yet, so none is corrected.
  - The **invoiced** part raises a credit note through
    `DocumentConverter::creditNote()` against the order's issued invoice —
    the ONE credit-note generator, with its own over-credit cap. The gross
    figure is the returned value scaled by the invoice's own effective rate
    (`total/subtotal`), so a 19.25% invoice produces a 19.25% credit and a
    zero-rated one credits net.
  - Returned invoiced quantity also comes off `quantity_invoiced`, so the
    line's story keeps adding up.
- Emits `order.return.recorded` on the order.

### 3. Credit control — in `Fulfilment::confirm()`

`confirm(SalesOrder $order, ?User $actor = null, ?string $creditOverride = null)`

- `contacts.credit_limit` **already existed** (CRM migration, model cast,
  API resource) — the additive column the brief asked for was already there,
  unread. No new column; the gap was the check.
- The figure compared is the customer's **overdue** balance from the existing
  `Aging` read model: `forParty()` total minus the `current` bucket. Not the
  whole balance — an invoice inside its terms is not a reason to hold goods.
- Zero or null limit = no check, exactly as specified.
- **Block with a named override**, not a warn: a Livewire "warning" is a
  toast somebody has already clicked past. When overdue > limit the confirm
  is refused with the two figures in the message; confirming anyway requires
  a written reason, stored on the order (`credit_override_reason`,
  `credit_override_by`) where an auditor will find it. The Show screen grows
  a reason field when the refusal happens.

### 4. Pick from location

`StockLedger::move()` names one location or none (`defaultLocationFor`);
`Fulfilment::deliver()` already followed that convention by stamping the
order's `stock_location_id` onto the note and its movements — but nothing
ever set it. The draft form now offers a location picker **only when the
`stock_locations` module is on** (`Modules::enabled`), validated to the
company, and `Fulfilment::create()` accepts `stock_location_id`. Reservations
stay company-wide (the deliberate limit from the first handoff); the location
says *which shelf the movement is written against*, following the ledger, not
inventing per-location holds.

### 5. Delivery note PDF

`?format=pdf` on the existing `orders.delivery-note` print route, through
`App\Support\Pdf::download()` with `Pdf::filename($note->number, 'Delivery
Note')` — the exact `PrintController` convention (`autoprint` forced false in
the PDF render).

### 6. Global search

Additive `GlobalSearch::sources()` + `GROUP_LABELS` entries:

- `SalesOrder` → title = number, subtitle = status · customer, ability
  `orders.view`, route `orders.show`. Cancelled orders stay findable (they
  answer "where is that order?" too); the subtitle says so.
- `DeliveryNote` → title = number, subtitle = customer + date, ability
  `orders.view`, route `orders.delivery-note` (the print page is the note's
  only page). Void notes return null and drop out of the index, same as void
  Documents.

The observer registration is automatic: the provider loops
`array_keys(sources())`.

### 7. Domain events

New `'orders'` module in `DomainEvents::CATALOGUE`: `order.confirmed`,
`order.delivered`, `order.invoiced`, `order.return.recorded` — past tense,
emitted from the services (never the components) via the existing
`EmitsDomainEvents` trait, now on `SalesOrder`.

## 8. Executive awareness — declined, with the snippet

The "short" figure belongs to `FulfilmentBoard`, and a Kpis-style addition
would be cheap — but `app/Support/Kpis.php` and
`app/Livewire/Reports/Executive.php` are the platform's files, outside this
vertical's ownership, and the alarms list is hand-assembled there. Forcing it
would mean editing files this pass must not touch. For the integrator, the
whole change is:

```php
// App\Support\Kpis
/** Units confirmed to customers that the shelf cannot cover today. */
public function backorderedUnits(): float
{
    return (new \App\Support\FulfilmentBoard($this->company))->rows()->sum('short');
}

// Executive::alarms(), one more row:
[
    'label' => 'Order units short',
    'value' => $kpis->backorderedUnits(),
    'money' => false,
    'caption' => 'Confirmed to customers, not on the shelf',
    'route' => route('orders'),
    'alarming' => $kpis->backorderedUnits() > 0,
],
```

One caveat the integrator should weigh: `FulfilmentBoard::rows()` loads every
open order with lines — fine at this product's scale, but it is a heavier
read than the other alarm sources.

## 9. Audit registrations (integrator)

Add to the `AuditObserver` loop in `AppServiceProvider` (commitments made,
custody of things):

- `\App\Models\SalesOrder::class` — confirming commits stock, overriding a
  credit block is exactly the kind of act the trail exists for.
- `\App\Models\DeliveryNote::class` — the paper that says goods left; a
  voided one must show who voided it.

Lines are left out for the same reason `JournalLine` is: the header carries
the story.

## Migration

`2026_09_16_000203_harden_sales_order_tables.php` — additive only, no
backfill:

- `sales_orders.source_document_id` (nullable ULID → documents, nullOnDelete)
- `sales_orders.credit_override_reason` (nullable string)
- `sales_orders.credit_override_by` (nullable BIGINT → users, nullOnDelete)
- `delivery_note_lines.quantity_returned` (decimal 15,3 default 0 — the same
  shape as every other quantity column on these tables)

## Demo seeder

`DemoModulesSeeder::orders()` gained two scenes, inside the existing
`SalesOrder::exists()` idempotence guard (seeds twice clean): a quotation for
paint drafted as an ordinary Document, issued through `DocumentIssuer`, and
converted with `fromQuotation()`; and the delivered rebar order's invoice
issued, 2 bars returned through `Returns::record()` — restocked with a
`'return'` movement and credited with an ordinary credit note.

## Tests

`tests/Feature/Orders/`: `FulfilmentTest` (first pass, untouched),
`ReturnsTest`, `CreditControlTest`, `QuotationToOrderTest`,
`OrdersSearchTest` — module switched on per test company, gates defined in
`setUp` (feature, not wiring), delivery-note PDF asserted at the
`Pdf::html()` step per that class's own doctrine.

## Deliberate limits

- A return is recorded against a delivery note, not free-floating: goods that
  come back came back from a delivery, and the note is the ceiling that makes
  "no more than was delivered" checkable.
- Returned goods do not re-reserve for anybody; they land as available stock.
- Credit control checks at **confirm** only — the commitment moment. Delivery
  of an already-confirmed order is not re-checked: the promise was made.
- The credit note goes against the order's most recent issued invoice with
  room left to credit; a business that needs line-perfect credit allocation
  across many part-invoices does that from the Documents screen, where
  partial credits already exist.
