# Industry verticals — Insurance, Supply Chain, Logistics, Real Estate

> **For agentic workers:** each vertical is one agent, run in parallel, new
> files only, integrated by the orchestrator. The rule that governs every
> decision below: **a vertical is a thin domain layer over the platform** —
> contacts, documents, invoices, the workflow engine, the service desk, fleet,
> dunning, e-signature, public tokens. A vertical that grows its own invoice
> generator or approval path has failed before it ships.

**Goal:** four switchable modules (all off by default) that make the platform
sellable to insurance brokers, distributors, transporters and property
managers — the four sectors the marketing pages can then name with evidence.

---

## 1. Insurance (brokers & agencies)

**Reuses:** policyholder + insurer are `Contact`s; premium invoicing through
the existing `Document` path with dunning and receipts; claim evidence is
managed documents; claim approval is the workflow engine; the renewals watch
copies `ContractWatch`'s three-list shape (coming / gone / gone-on-auto-renew).

**New:** `insurance_policies` (holder, insurer, product line, premium, cover
from/to, renewal terms, commission %), `insurance_claims` (policy, incident
date, description, status FNOL→assessed→settled/rejected, settled amount),
`policy_commissions` (what the insurer owes the broker — receivable, not a
second ledger). Read model `PolicyWatch`. Screens: policies index/show, claims
board. The alarm: cover that lapses before renewal is agreed.

## 2. Supply chain (distributors & wholesalers)

Procurement, replenishment, multi-location stock and lot tracking already
exist — the missing half is **outbound**: `sales_orders` (+lines) with status
draft→confirmed→picking→delivered→invoiced, consuming the existing
reservations on confirm, releasing on cancel; `delivery_notes` printed through
the one print pipeline with QR verification; backorders (the unfulfillable
remainder of a confirmed order, visible, never silent); invoicing generates an
ordinary `Document` from the delivered lines — one invoice generator. Read
model `FulfilmentBoard`: what is promised, what can ship today, what is short.

## 3. Logistics (transporters)

**Reuses:** vehicles ARE fleet assets (trips, fuel, odometer servicing);
drivers are users/employees; proof of delivery is the existing e-signature
path; public shipment tracking follows the share-token pattern; freight
charges invoice through the existing issuer.

**New:** `shipments` (sender, receiver — Contacts; cargo description, weight,
declared value, route from/to, status booked→loaded→in-transit→delivered,
public tracking token), `trip_manifests` (vehicle + driver + date + the
shipments aboard; loading writes shipment status, and a shipment can only be
aboard one open manifest). POD: delivery captures a signature through the
existing signature flow and stamps the shipment. Screen: dispatch board.
Public: `/track/{token}` shows status history, nothing else — no names of
other customers' cargo.

## 4. Real estate (agencies & landlords)

**Reuses:** a lease IS a `Contract` (auto-renew, notice_by, the watch);
rent is the existing recurring-invoice path with dunning; a maintenance
request IS a service ticket; the property's paper trail is managed documents;
the landlord statement mirrors `SupplierAccount`/`Statement`.

**New:** `properties` (+units: address, type, landlord Contact, target rent),
`tenancies` linking unit + tenant Contact + the lease Contract + deposit held
(a liability, posted to the books through the existing ledger — never a memo
field), move-in/out with deposit settlement. Read model `OccupancyBoard`:
vacant units, arrears by tenancy (through existing aging), leases ending.
Screens: properties index/show, tenancy show.

---

## Cross-cutting (orchestrator, after agents land)

- Four `config/modules.php` entries, off by default, with honest `requires`.
- Permission groups: `Insurance`, `Orders`, `Logistics`, `Estate` — view /
  manage / the one money-committing act each (settle claim, confirm order,
  dispatch manifest, close tenancy) split out, seeded to Manager minus the
  money act, following the house doctrine.
- Routes + nav + four guides + `DemoModulesSeeder` scenes (a lapsing policy,
  a short order, an in-transit manifest, a tenancy in arrears).
- Marketing: add the four sectors WITH evidence, once real.
- Full suite, schema export, commit per integration.

**Sequencing:** all four agents in parallel (new namespaces, no shared-file
collisions); migration prefixes `2026_09_15_0001xx`–`0004xx`; the usual
hard-won-lessons block in every prompt.
