# Estate hardening — gap scan and what was built

Scan of the shipped Real-estate vertical (`docs/handoff/estate.md`) against the
verticals plan §4 and the platform-sync checklist. Each gap verified in code
before being listed; each build went through the existing paths only.

## Gap list (verified, then built)

| # | Gap | Found | Done |
| --- | --- | --- | --- |
| 1 | **Landlord statement** | Missing entirely: no statement class, no commission column on `properties`, no way to tie an expense to a property. | `app/Support/LandlordStatement.php`, mirroring `SupplierAccount` (read-only, date-ordered lines, running balance, figures from the classes that own them). Additive columns: `properties.commission_percent`, `expenses.property_id`. |
| 2 | **Paying the landlord out** | Missing. | `app/Services/Estate/Landlords.php` — `payOut()` records an ordinary Expense to the landlord contact **through ExpenseRecorder** (never a parallel payment path), pinned to the property, reference `LL-PAYOUT {period}` so the statement shows it as a payout line. Refuses paying more than is owed. |
| 3 | **Rent review / escalation** | Missing: no history, no way to change the billed amount. | `tenancy_rent_changes` table + `TenancyRentChange` model; `Tenancies::reviewRent()` keeps the history, updates the tenancy and rewrites the schedule's line through the existing recurring mechanism. Never retroactive: an effective date before today, or after the schedule's next run, is refused in words. |
| 4 | **Move-in/out inspection** | `Property::papers()` existed; `Tenancy` had no document relations. | Reused the managed-documents machinery rather than building an inspection engine: `Tenancy::documentRelations()`/`papers()` (same shared `business_document_relations` table as Contract/Property), so an inspection checklist paper (Documents 2.14 checklists) attaches straight to the tenancy. Argued below. |
| 5 | **Unit maintenance visibility** | Partial: property Show listed tickets but without saying which door. | Tickets on the property page now carry their unit's label (`service_tickets.property_unit_id` joined to the loaded units). |
| 6 | **Global search** | `Property` and `Tenancy` absent from `App\Search\GlobalSearch`. | Added both sources + group labels, ability `estate.view`, routing to `estate.show`. |
| 7 | **Domain events** | No `estate` module in `DomainEvents::CATALOGUE` although the service already emitted `estate.tenancy.started/ended`. | Catalogued `estate.tenancy.started`, `estate.tenancy.ended`, `estate.tenancy.rent-changed` (all now emitted). |
| 8 | **Audit** | No estate model is registered with `AuditObserver` (`app/Providers/AppServiceProvider.php` — untouched per ownership). | Integrator: add `Property::class, PropertyUnit::class, Tenancy::class, TenancyRentChange::class` to the observed list. Property (commission % is money), Tenancy (deposit refs), rent changes (a restatement of what a tenant owes monthly) all meet the "who changed this, from what" bar. |

## The landlord statement, argued

Per landlord, per period, read-only. Rent **collected** (payments received in
the period against invoices issued to the property's tenants inside their
tenancy windows — collected, not billed, because the landlord is owed what came
in), minus the agency's commission at the property's `commission_percent`,
minus expenses recorded against the property (`expenses.property_id`), minus
payouts already made — closing balance is what the landlord is owed. Sign
convention mirrors `SupplierAccount`: balances run positive when money is due
out. Opening balance is the same arithmetic before the period, so a past
period stays right about that period.

Attribution of a payment to a property: the paying contact is a tenant of one
of its units and the invoice was issued inside that tenancy's window. The
generated invoice does not store its schedule id (a core-table change this
agent does not own); the window test is exact for an agency, whose tenants'
invoices are rent. Stated here so a future integrator can tighten it by adding
`documents.recurring_invoice_id` in the generator.

## The payout, argued (why an Expense)

The shipped design books rent as agency revenue through ordinary invoices, so
the remittance out is a cost of the letting activity: an Expense to the
landlord contact through `ExpenseRecorder` — payables, settlement, AC journal
and audit all come free, and the AP aging shows unpaid landlords the same way
it shows unpaid suppliers. The expense is pinned `property_id` and referenced
`LL-PAYOUT <from>..<to>`; `LandlordStatement` classifies a pinned expense whose
supplier is the property's landlord as a *payout* line, anything else pinned as
a *property expense* line — both deductions, labelled honestly.

## Inspections, argued (reuse over engine)

Documents 2.14 already ships checklists on managed papers. An inspection IS a
paper: a checklist template, filled at move-in or move-out, attached. All the
tenancy needed was the same `documentRelations()` morph the Property and
Contract already have — a "papers on this tenancy" panel, zero new engine. The
awkward part (creating the paper from a template) already lives in the
Documents screens; the estate page links, it does not fork.

## New route (routes/* untouched)

None. Everything lands on the existing `estate.show` page.

## Migration

`database/migrations/2026_09_15_000402_add_landlord_statement_and_rent_review_columns.php`
— additive only, no default backfill: `properties.commission_percent`
(nullable), `expenses.property_id` (nullable FK), new `tenancy_rent_changes`
table. Longest generated identifier is
`tenancy_rent_changes_recurring_invoice_id_foreign` — named explicitly in the
migration to stay well under 64 chars; all others are under the limit.

## Demo

`DemoModulesSeeder::estate()` now seeds a computable statement: 10% commission
on Immeuble Bonanjo, the arrears tenant's first invoice actually paid (rent
collected in), one plumbing expense pinned to the property, and a rent review
on the occupied tenancy (150 000 → 165 000 from next month, history row kept).
Idempotent — guarded by `Property::exists()` as before; seeds twice clean.

## Verify

```powershell
$env:Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;$env:Path"
php artisan test --filter="Estate|Tenanc|Landlord|Search|Aging|Expense"
```

Trial balance asserted to foot in every money test. Pint clean.

At handover: 12 new estate tests green, all 19 prior estate tests green, and
the tenant-trait guard passes for every estate model (TenancyRentChange
included). Two failures in the wider filter are **not estate's**: 
`Orders\OrdersSearchTest` (missing `orders.show` route param) and
`Search\GlobalSearchTest` reindex (`App\Models\Shipment` lacks SoftDeletes) —
both from the Orders/Logistics verticals' own entries.
