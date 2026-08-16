# Real estate (agencies & landlords) — integration notes

Verticals plan §4. A vertical is a thin domain layer over the platform, and this one
owns almost nothing: **a lease IS a Contract, rent IS a recurring invoice, a deposit
IS a posted liability, a maintenance request IS a service ticket, landlord and tenant
are Contacts.** The new tables are only the geography — properties, units, tenancies.

## What shipped

| Piece | File |
| --- | --- |
| Migration: `properties`, `property_units`, `tenancies` + nullable `service_tickets.property_unit_id` | `database/migrations/2026_09_15_000401_create_estate_tables.php` |
| Models | `app/Models/Property.php`, `PropertyUnit.php`, `Tenancy.php` |
| Additive relations | `Contact::propertiesOwned()`, `Contact::tenancies()`, `Contract::tenancy()` (only edits to existing files) |
| Service: move-in / move-out / maintenance | `app/Services/Estate/Tenancies.php` |
| Read model: vacancy, arrears, endings | `app/Support/OccupancyBoard.php` |
| Screens | `app/Livewire/Estate/Index.php`, `Show.php` + `resources/views/livewire/estate/index.blade.php`, `show.blade.php` |
| Tests | `tests/Feature/Estate/TenancyTest.php` (16), `EstateScreenTest.php` (3) |

## How the platform is reused (the non-negotiables, honoured)

- **Lease** — `Tenancies::start()` raises a Contract (`type: 'lease'`, tenant as
  counterparty, rent as value) through `ContractLifecycle::raise()` and activates it.
  Renewal, `notice_by` and the watch come free; `OccupancyBoard::leasesEnding()` is
  `ContractWatch::expiring()` *filtered* to leases on open tenancies, never forked.
  Move-out terminates the lease through `ContractLifecycle::terminate()`.
- **Rent** — an ordinary `RecurringInvoice` (monthly, `auto_issue: true`, terms 7 days
  by default). The nightly generator dates each invoice on its period and dunning
  chases it without knowing estate exists. Move-out marks the schedule `finished`.
- **Deposit** — posted through `Ledger::post()` at receipt (Dr 521 bank / Cr 165),
  journal `BQ`, with the tenancy as idempotency source; settled at move-out in one
  entry (Dr 165 full deposit / Cr 521 refund / Cr 7078 retained). Entry ids stored on
  the tenancy (`deposit_entry_id`, `deposit_settlement_entry_id`). Never a memo column.
- **Arrears** — `OccupancyBoard::arrears()` returns `Aging::forParty()`'s own row per
  tenant; the test asserts byte-for-byte equality with Aging's figure.
- **Maintenance** — `Tenancies::reportMaintenance()` opens a `ServiceTicket` through
  `TicketDesk::open()` (category `maintenance`, channel `internal`), then pins
  `property_unit_id` so a property page lists its own tickets.

## The deposit account, argued

**165 « Dépôts et cautionnements reçus »**, not the 4191 family. 4191 is a customer's
advance *against future invoices* — money on its way to becoming revenue. A caution is
never that: it is held for the term of the lease and returned, which is class 1's
"ressources durables" and why the plan gives deposits received their own account there.
Only the **retained** part becomes income, at settlement and for a stated recorded
reason, through **7078 « Autres produits accessoires »** — accessory income of the
letting activity, not a sale. Both accounts are `firstOrCreate`d on first use (module
ships off; a business that never lets a room never grows them), with sides derived from
`ChartOfAccounts::normalBalanceFor()`. No edit to the seeded chart.

## Business-readable refusals

- Second tenant into an occupied unit → refused, naming the sitting tenant.
- Move-out with unpaid rent → refused, naming tenant and balance; `force: true` +
  `force_reason` overrides, and the reason is appended to the tenancy notes.
- Retaining deposit without a reason, or more than was held → refused.
- Ending twice, zero rent, negative deposit, move-out before move-in → refused.

## Routes (owned by the integrating agent — routes/* untouched)

```php
Route::get('/estate', App\Livewire\Estate\Index::class)->name('estate');
Route::get('/estate/{property}', App\Livewire\Estate\Show::class)->name('estate.show');
```

Authenticated, company-scoped group. The screen tests register these names test-locally.

## Permission group — `Estate`, argued

- `estate.view` — see the board and the properties. Read-only.
- `estate.manage` — add properties/units, move tenants in, log maintenance. Moving a
  tenant in *creates* obligations (a lease, a billing schedule) the same way raising a
  contract or a schedule does elsewhere, so it sits at manage level.
- `estate.end-tenancy` — the one money-committing act, split out per house doctrine:
  ending a tenancy **moves deposit money** — it decides how much of the tenant's
  caution the business keeps as income and pays the rest out of the bank, and it can
  force past unpaid rent. Seed to Manager *minus* this key; Owner/senior gets it.

No `approve` key anywhere; the lease itself can still go through the contracts
workflow if a business submits it.

Gates are defined test-locally in `tests/Feature/Estate/*` (as manufacturing did)
pending cataloguing in `Support\Permissions` by the integrator.

## Modules entry (config/modules.php untouched, per ownership)

```php
'estate' => [
    'label' => 'Property management',
    'description' => 'Buildings and units, tenancies with their leases, deposits in the books, and rent that bills itself.',
    'icon' => 'briefcase',
    'default' => false, // a vertical: most businesses let nothing
    // Tenants/landlords are Contacts; the lease machinery is contracts;
    // rent invoices and dunning are sales.
    'requires' => ['customers', 'contracts', 'sales'],
    'groups' => ['estate'],
    'models' => [Property::class, PropertyUnit::class, Tenancy::class],
],
```

The service desk is deliberately **not** required: maintenance is an optional path,
and `reportMaintenance()` is only reachable from a screen a business chooses to use.
If the desk module is off, the integrator may hide the "Report a fault" button behind
`service.view` as well.

## Nav suggestion

"Properties" entry (active state `estate`), grouped with Contracts; badge with
`OccupancyBoard::summary()['tenancies_in_arrears']` if a badge is cheap.

## Guide sketch (resources/guides/ untouched)

**"Letting a property"** — add the building and its units with asking rents; move a
tenant in (the deposit goes straight into the books as money you owe back; the lease
lands on the contract register with its notice date; the rent bills itself monthly and
overdue rent is chased like any invoice); report faults to the service desk; move out
by settling the deposit — what you keep needs a reason and becomes income, the rest is
refunded.

## Demo scene sketch (DemoModulesSeeder, owned by orchestrator)

One property ("Immeuble Bonanjo", landlord contact), three units: one vacant with an
asking rent, one tenancy in arrears (issued unpaid rent invoice 45 days past due), one
tenancy whose lease ends inside 30 days with a notice period — so the landing board
shows all three lists populated.

## Verify

```powershell
$env:Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;$env:Path"
php artisan test --filter="Estate|Tenanc|Contract|Aging|Ledger"
```

19 estate tests (78 assertions) green at handover; full regression filter
`Estate|Tenanc|Contract|Aging|Ledger|Recurring|Service|Statement|Contact` ran 325
tests with **one pre-existing failure not from this vertical**: the tenant-trait guard
(`Tests\Feature\TenancyTest`, note: name collision is namespace-only) fails on
`App\Models\TripManifestShipment` — the Logistics agent's model missing
`BelongsToCompany`. All estate models pass the same guard. Pint clean.

## Open edges

- No edit UI for a property/unit after creation (plain columns; additive later).
- Statement for a landlord: the plan's "landlord statement mirrors Statement" is
  satisfied read-only via `Contact` + existing `Statement` on the landlord contact
  once landlord remittances exist; remitting collected rent to the landlord (the
  agency case) is a follow-on that would post through the ledger like the deposit.
- `service_tickets.property_unit_id` is written by `reportMaintenance()` only; the
  service desk screens ignore it harmlessly.
