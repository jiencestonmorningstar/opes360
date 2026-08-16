# Insurance vertical — integration handoff

New files only. Nothing in `routes/*`, `config/*`, `app/Providers/*`,
`Permissions.php`, `Guides.php` or the seeders was touched; everything the
integrator needs to wire is listed here, with the reasoning to carry into the
comments.

## What shipped

- Migration `database/migrations/2026_09_15_000101_create_insurance_tables.php`
  — `insurance_policies`, `insurance_policy_invoices` (link table to premium
  invoices), `insurance_claims`, `policy_commissions`. Long index/FK names set
  by hand under MySQL's 64-char limit (`ins_*` prefixes).
- Models `InsurancePolicy` (stored `notice_by` recomputed on every save, the
  contracts pattern), `InsuranceClaim` (Approvable — settlement goes through
  the shared engine), `PolicyCommission`, `InsurancePolicyInvoice`.
- Services `App\Services\Insurance\Policies` (place, bind, cancel,
  invoicePremium, recordCommission, invoiceCommission) and
  `App\Services\Insurance\Claims` (open, assess, submitSettlement, settle,
  reject). **There is no `approve()` anywhere and a test asserts it stays
  absent** (`ClaimSettlementTest::test_nothing_in_the_vertical_grew_an_approve_method`).
- Read model `App\Support\PolicyWatch` — cover lapsing / lapsed /
  lapsed-on-auto-renew, the ContractWatch three-list shape. The alarm is cover
  that lapses before renewal is agreed.
- Listener `App\Listeners\SettleApprovedInsuranceClaims` — generic
  `workflow.approved` listener, mirrors `ActivateApprovedContracts`.
- Screens `App\Livewire\Insurance\Index` (watch first, then register, plus a
  claims-board tab) and `Show` (cover, premium invoices, claims, commissions).
- Tests `tests/Feature/Insurance/*` — 23 passing. They register the routes,
  gates and listener locally, so they keep passing before and after the wiring
  below lands (duplicate listener registration is harmless: `settle()` is
  idempotent on a settled claim).
- Additive relations on `Contact`: `policiesHeld()`, `policiesUnderwritten()`,
  `policyCommissions()`.

## Routes (routes/web.php)

```php
use App\Livewire\Insurance\Index as InsuranceIndex;
use App\Livewire\Insurance\Show as InsuranceShow;

// The watch — cover about to lapse unagreed — is the landing page rather
// than a tab off a list, for the same reason as /contracts.
Route::get('/insurance', InsuranceIndex::class)
    ->middleware('can:insurance.view')->name('insurance');
Route::get('/insurance/{policy}', InsuranceShow::class)
    ->middleware('can:insurance.view')->name('insurance.show');
```

`{policy}` binds `App\Models\InsurancePolicy` (rename the parameter or type-hint
as elsewhere in the file; the component signature is `mount(InsurancePolicy $policy)`).

## Permission group (Permissions.php CATALOGUE)

```php
/*
 * The brokerage book. `settle` is split out because settling a claim is the
 * money-committing act — the number the client is paid — and stays above
 * Manager, the way rfq-award and payables.execute do. Placing and binding
 * cover is `manage`; there is no `approve`, as everywhere else: being asked
 * by the workflow is the permission.
 */
'Insurance' => ['view', 'manage', 'settle'],
```

Seeder grants argued: `insurance.view` to Staff and up; `insurance.manage` to
Manager and up; `insurance.settle` to **Administrator/Owner only** — it is the
one act that commits money to a client, and one person assessing while another
settles is what makes the workflow a control rather than a speed bump.

## Module entry (config/modules.php)

```php
'insurance' => [
    'label' => 'Insurance',
    'description' => 'Policies, claims and commissions for brokers and agencies.',
    'default' => false,          // ships off
    'requires' => ['customers', 'sales'],   // holder/insurer are Contacts; premiums are Documents
    'groups' => ['insurance'],
    'models' => [App\Models\InsurancePolicy::class, App\Models\InsuranceClaim::class],
],
```

(Adjust `requires` keys to the catalogue's actual names for the contacts and
sales modules.)

## Listener (AppServiceProvider / wherever DomainEvent listeners are bound)

```php
Event::listen(DomainEvent::class, SettleApprovedInsuranceClaims::class);
```

## Nav

One entry, `active => 'insurance'`, label **Insurance**, route `insurance`,
behind `insurance.view` + the module switch — alongside Contracts.

## Guide sketch (resources/guides)

1. Place a policy (client = a customer contact, insurer = a supplier contact).
2. Bind it — the cover joins the watch.
3. Invoice the premium: a draft appears in Sales, issued/dunned there.
4. Record commission and invoice the insurer for it.
5. A claim: notify → assess → send settlement for approval → the approver's
   yes settles it. Attach evidence from Documents.
6. Read the watch: cover ending, cover lapsed, and the red list — auto-renewed
   past its date, unagreed and unbilled.

## Demo scene (DemoModulesSeeder)

A lapsing policy: motor cover for a named client, `covers_to` about ten days
out, `renewal_type => 'manual'`, an issued premium invoice partly paid, one
claim in `assessed` with a settlement awaiting approval, one earned commission
not yet invoiced. Add a second policy `renewal_type => 'auto'` with
`covers_to` last week so the alarm banner shows.

## Verification

`php artisan test --filter="Insurance|Contract|Workflow"` — 23 + 178 passing.
Pint clean on all new files.
