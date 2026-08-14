# VIP Membership Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A paid, tiered membership programme where buying a tier applies an automatic discount to that customer's invoices for the term of the membership.

**Architecture:** Discounting is first made to work inside `Vat::compute`, which is where every invoice total in the books is calculated — it does not work today. VIP then sits on top: `vip_tiers` and `vip_memberships` tables, a `VipMemberships` service that sells through the existing `DocumentIssuer` and `PaymentRecorder`, and a rule that stamps the member's tier discount onto an invoice as it is raised.

**Tech Stack:** Laravel 12, Livewire 3, Tailwind v4, MySQL/SQLite, Pest/PHPUnit, Sanctum.

**Spec:** `docs/superpowers/specs/2026-08-14-vip-membership-design.md`

**PHP binary:** `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` — on this machine `php` is not on PATH by default. Prefix commands with:
`export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:$PATH"`

---

## File Structure

**Created**

| File | Responsibility |
|---|---|
| `database/migrations/2026_08_19_000001_create_vip_tables.php` | `vip_tiers`, `vip_memberships` |
| `app/Models/VipTier.php` | A tier a business offers |
| `app/Models/VipMembership.php` | One membership sold, with its frozen terms |
| `app/Policies/VipMembershipPolicy.php` | Authorisation, following `DealPolicy` |
| `app/Services/VipMemberships.php` | sell / extend / cancel / expire — the only place these happen |
| `app/Livewire/Vip/Tiers.php` + view | Manage tiers |
| `app/Livewire/Vip/Members.php` + view | List and sell memberships |
| `app/Http/Controllers/Api/VipController.php` | API |
| `app/Http/Resources/VipTierResource.php`, `VipMembershipResource.php` | Wire shapes |
| `app/Console/Commands/ExpireVipMemberships.php` | Nightly sweep |
| `database/factories/VipTierFactory.php`, `VipMembershipFactory.php` | Test fixtures |
| `tests/Unit/VatDiscountTest.php` | The tax-base rules |
| `tests/Feature/VipMembershipTest.php` | Lifecycle and screens |
| `tests/Feature/VipApiTest.php` | API, scopes, tenancy |

**Modified**

| File | Change |
|---|---|
| `app/Support/Vat.php` | `compute()` and `forCompany()` accept a discount and return `discount_total` |
| `app/Http/Controllers/Api/DocumentController.php` | Apply a member's discount when raising an invoice |
| `app/Support/Permissions.php` | Add the `Vip` group |
| `config/modules.php` | Register `vip`, defaulting off |
| `config/opes.php` | Navigation entry |
| `app/Providers/AuthServiceProvider.php` | Register `VipMembershipPolicy` if policies are listed explicitly |
| `routes/web.php`, `routes/api.php` | Routes |
| `app/Support/WebhookEvents.php` | Three new events |
| `app/Console/Kernel.php` or `routes/console.php` | Schedule the sweep |
| `docs/API.md`, `app/Console/Commands/ExportOpenApi.php` | Document it |

---

## Task 1: Make discounts work in `Vat::compute`

Nothing VIP-specific. This is a standalone correctness fix and ships on its own.

**Files:**
- Modify: `app/Support/Vat.php`
- Test: `tests/Unit/VatDiscountTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/VatDiscountTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\Vat;
use PHPUnit\Framework\TestCase;

/**
 * A discount reduces the taxable base.
 *
 * TVA is owed on what was actually charged. Computing it on the pre-discount
 * figure hands the DGI money that was never collected, on every discounted
 * invoice — which is the whole reason this lives in one tested function.
 */
class VatDiscountTest extends TestCase
{
    /** @return array<int, array{quantity: float, unit_price: float}> */
    protected function lines(float $amount = 100000): array
    {
        return [['quantity' => 1, 'unit_price' => $amount]];
    }

    public function test_no_discount_leaves_the_totals_alone(): void
    {
        $result = Vat::compute($this->lines(), 19.25, true, false, 'XAF');

        $this->assertSame(0.0, $result['discount_total']);
        $this->assertSame(100000.0, $result['subtotal']);
        $this->assertSame(119250.0, $result['total']);
    }

    /**
     * The worked example from the spec. It lands on an exact half — 85,000 at
     * 19.25% is 16,362.5 — so the expected value is stated rather than
     * recomputed: PHP rounds half away from zero and gives 16,363.
     */
    public function test_tax_is_computed_on_the_discounted_base(): void
    {
        $result = Vat::compute($this->lines(), 19.25, true, false, 'XAF', 15.0);

        $this->assertSame(100000.0, $result['subtotal']);
        $this->assertSame(15000.0, $result['discount_total']);
        $this->assertSame(16363.0, $result['tax_total']);
        $this->assertSame(101363.0, $result['total']);
    }

    public function test_the_discount_applies_without_vat_registration(): void
    {
        $result = Vat::compute($this->lines(), 0, false, false, 'XAF', 15.0);

        $this->assertSame(15000.0, $result['discount_total']);
        $this->assertSame(0.0, $result['tax_total']);
        $this->assertSame(85000.0, $result['total']);
    }

    /**
     * When prices are keyed TTC the discount comes off the gross, and net and
     * tax are re-extracted from what is left — otherwise the parts stop adding
     * up to the whole.
     */
    public function test_a_tax_inclusive_price_discounts_the_gross(): void
    {
        $result = Vat::compute($this->lines(119250), 19.25, true, true, 'XAF', 10.0);

        $gross = $result['total'];
        $this->assertSame(107325.0, $gross);
        $this->assertSame($gross, round($result['subtotal'] - $result['discount_total'] + $result['tax_total'], 0));
    }

    public function test_net_plus_tax_equals_gross_with_a_discount(): void
    {
        foreach ([5.0, 12.5, 33.3, 99.0] as $percent) {
            $r = Vat::compute($this->lines(87654), 19.25, true, false, 'XAF', $percent);

            $this->assertSame(
                $r['total'],
                round($r['subtotal'] - $r['discount_total'] + $r['tax_total'], 0),
                "parts must add up at {$percent}%"
            );
        }
    }

    public function test_xaf_totals_stay_whole_francs(): void
    {
        $r = Vat::compute($this->lines(33333), 19.25, true, false, 'XAF', 7.0);

        foreach (['subtotal', 'discount_total', 'tax_total', 'total'] as $key) {
            $this->assertSame(round($r[$key]), $r[$key], "{$key} must be whole");
        }
    }

    public function test_a_discount_of_a_hundred_percent_leaves_nothing_owing(): void
    {
        $r = Vat::compute($this->lines(), 19.25, true, false, 'XAF', 100.0);

        $this->assertSame(100000.0, $r['discount_total']);
        $this->assertSame(0.0, $r['total']);
    }

    /** A nonsense percentage must not invert the invoice. */
    public function test_the_discount_is_clamped_to_a_sane_range(): void
    {
        $this->assertSame(0.0, Vat::compute($this->lines(), 0, false, false, 'XAF', -20.0)['discount_total']);
        $this->assertSame(100000.0, Vat::compute($this->lines(), 0, false, false, 'XAF', 250.0)['discount_total']);
    }
}
```

- [ ] **Step 2: Run the tests and watch them fail**

```bash
export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:$PATH"
php artisan test --filter=VatDiscountTest
```

Expected: every test fails — `compute()` takes five arguments and returns no `discount_total`.

- [ ] **Step 3: Add the discount to `Vat::compute`**

In `app/Support/Vat.php`, change the signature of `compute()` to accept a sixth argument and rewrite the return block. Replace lines 60–116 (`public static function compute` through its closing brace) with:

```php
    public static function compute(
        array $lines,
        float $rate,
        bool $registered,
        bool $pricesIncludeTax,
        string $currency = 'XAF',
        float $discountPercent = 0.0,
    ): array {
        $applies = $registered && $rate > 0;
        $decimals = self::decimalsFor($currency);

        // A percentage outside 0–100 is a mistake, not an instruction. Left
        // unclamped, a negative one would add money to the invoice and one
        // over 100 would make the customer a creditor.
        $discountPercent = max(0.0, min(100.0, $discountPercent));

        $computed = [];

        foreach ($lines as $line) {
            $quantity = (float) ($line['quantity'] ?? 0);
            $amount = $quantity * (float) ($line['unit_price'] ?? 0);

            if (! $applies) {
                $net = round($amount, $decimals);
                $computed[] = [
                    'net' => $net, 'tax' => 0.0, 'gross' => $net,
                    'unit_net' => self::unitNet($net, $quantity),
                ];

                continue;
            }

            if ($pricesIncludeTax) {
                // The keyed figure is the gross, so the net is extracted from it
                // and the tax is the remainder. Taking the remainder rather than
                // recomputing it guarantees net + tax == gross exactly, with no
                // stray minor unit appearing between the line and its total.
                $gross = round($amount, $decimals);
                $net = round($gross / (1 + ($rate / 100)), $decimals);
                $tax = round($gross - $net, $decimals);
            } else {
                $net = round($amount, $decimals);
                $tax = round($net * ($rate / 100), $decimals);
                $gross = round($net + $tax, $decimals);
            }

            $computed[] = [
                'net' => $net, 'tax' => $tax, 'gross' => $gross,
                'unit_net' => self::unitNet($net, $quantity),
            ];
        }

        // Summed from the rounded per-line figures, which are the ones printed
        // — so the column adds up to the total beneath it.
        $subtotal = round(array_sum(array_column($computed, 'net')), $decimals);
        $taxTotal = round(array_sum(array_column($computed, 'tax')), $decimals);
        $total = round(array_sum(array_column($computed, 'gross')), $decimals);
        $discountTotal = 0.0;

        /*
         * The discount reduces the taxable base.
         *
         * TVA is owed on what was actually charged, so the tax is recomputed
         * from what is left after the discount rather than carried over from
         * the full-price lines. The per-line figures stay at list price: they
         * are what the customer reads down the page, and the discount is shown
         * as its own figure beneath them.
         */
        if ($discountPercent > 0) {
            $fraction = $discountPercent / 100;

            if ($applies && $pricesIncludeTax) {
                // Keyed TTC: take the discount off the gross, then re-extract.
                $discountedGross = round($total * (1 - $fraction), $decimals);
                $newNet = round($discountedGross / (1 + ($rate / 100)), $decimals);

                $discountTotal = round($subtotal - $newNet, $decimals);
                $taxTotal = round($discountedGross - $newNet, $decimals);
                $subtotalAfter = $newNet;
                $total = $discountedGross;
            } else {
                $discountTotal = round($subtotal * $fraction, $decimals);
                $subtotalAfter = round($subtotal - $discountTotal, $decimals);
                $taxTotal = $applies ? round($subtotalAfter * ($rate / 100), $decimals) : 0.0;
                $total = round($subtotalAfter + $taxTotal, $decimals);
            }

            unset($subtotalAfter);
        }

        return [
            'lines' => $computed,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => $total,
            'rate' => $rate,
            'applies' => $applies,
        ];
    }
```

Also update the docblock above `compute()` so the return shape lists `discount_total: float`.

- [ ] **Step 4: Let `forCompany` pass a discount through**

Replace the `forCompany` method (around line 42) with:

```php
    public static function forCompany(Company $company, array $lines, float $discountPercent = 0.0): array
    {
        return self::compute(
            $lines,
            (float) $company->vat_rate,
            (bool) $company->vat_registered,
            (bool) $company->prices_include_tax,
            (string) ($company->currency ?: 'XAF'),
            $discountPercent,
        );
    }
```

- [ ] **Step 5: Run the tests and verify they pass**

```bash
php artisan test --filter=VatDiscountTest
```

Expected: 8 passed.

- [ ] **Step 6: Run the whole suite — this function is load-bearing**

```bash
php artisan test
```

Expected: everything that passed before still passes. `discount_total` is additive and defaults to zero, so no existing caller changes behaviour. If anything fails here, stop and fix it before continuing — a regression in this function is a regression in every invoice.

- [ ] **Step 7: Commit**

```bash
git add app/Support/Vat.php tests/Unit/VatDiscountTest.php
git commit -m "Make a discount reduce the taxable base

document_lines has carried discount_type and discount_value since the sales
tables were created, and DocumentConverter copies both when a quotation
becomes an invoice — but Vat::compute never read them, so a discount could be
stored, carried forward, and change no total.

The rule that matters is that TVA is owed on what was actually charged.
Computing it on the pre-discount figure hands the DGI money that was never
collected. Where prices are keyed TTC the discount comes off the gross and net
and tax are re-extracted from what is left, so the parts still add up to the
whole.

The percentage is clamped: a negative one would add money to the invoice and
one over 100 would make the customer a creditor."
```

---

## Task 2: Tables, models and the module switch

**Files:**
- Create: `database/migrations/2026_08_19_000001_create_vip_tables.php`, `app/Models/VipTier.php`, `app/Models/VipMembership.php`, `app/Policies/VipMembershipPolicy.php`
- Modify: `app/Support/Permissions.php`, `config/modules.php`, `config/opes.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vip_tiers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 14, 2)->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->unsignedSmallInteger('period_months')->default(12);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->text('perks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('vip_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vip_tier_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The terms are copied, not referenced.
             *
             * Raising Gold's discount next year must not silently rewrite what
             * an existing member was sold, and lowering its price must not
             * rewrite what they paid. The membership records the terms it was
             * sold under; the tier is where future sales get theirs. Same
             * reasoning as storing an expense's ledger account on the expense.
             */
            $table->string('tier_name');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('price_paid', 14, 2)->default(0);
            $table->string('currency', 3)->default('XAF');

            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('active'); // active|expired|cancelled

            // The invoice that sold it. A membership whose invoice was voided
            // is not active, so the link has to be kept.
            $table->foreignUlid('document_id')->nullable()->constrained('documents')->nullOnDelete();

            $table->string('card_number')->nullable();
            $table->foreignUlid('verification_token_id')->nullable()
                ->constrained('verification_tokens')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // "Is this customer a member right now" and the expiry sweep.
            $table->index(['company_id', 'contact_id', 'status']);
            $table->index(['company_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vip_memberships');
        Schema::dropIfExists('vip_tiers');
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: both tables created.

- [ ] **Step 3: Write the models**

`app/Models/VipTier.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A tier a business offers. What a membership is sold *from*. */
class VipTier extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(VipMembership::class);
    }
}
```

`app/Models/VipMembership.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One membership sold, carrying the terms it was sold under.
 *
 * `isActive()` asks the dates rather than trusting `status`: an invoice raised
 * the day after a membership lapses must get no discount even if the nightly
 * sweep has not run yet. The sweep tidies state; it is not what enforces the
 * rule.
 */
class VipMembership extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'price_paid' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(VipTier::class, 'vip_tier_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE
            && $this->starts_on?->isToday() !== null
            && $this->starts_on->lte(now())
            && $this->ends_on->gte(now()->startOfDay());
    }

    /** The rate to apply today: zero unless the membership is genuinely live. */
    public function effectiveDiscount(): float
    {
        return $this->isActive() ? (float) $this->discount_percent : 0.0;
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->whereDate('starts_on', '<=', now())
            ->whereDate('ends_on', '>=', now());
    }
}
```

- [ ] **Step 4: Write the policy**

`app/Policies/VipMembershipPolicy.php`:

```php
<?php

namespace App\Policies;

class VipMembershipPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'vip';
    }
}
```

- [ ] **Step 5: Add the permission group**

In `app/Support/Permissions.php`, after the `'Loyalty'` line (line 55), add:

```php
        'Vip' => ['view', 'manage', 'sell'],
```

`sell` is separate from `manage` for the same reason `sales.issue` is separate from `sales.create`: a receptionist may sign somebody up without being able to redesign the programme.

- [ ] **Step 6: Register the module**

In `config/modules.php`, after the `loyalty` entry, add:

```php
    'vip' => [
        'label' => 'VIP membership',
        'description' => 'Paid tiers that discount a member’s invoices for the term they bought.',
        'icon' => 'crown',
        /*
         * The one module that ships off.
         *
         * Every other module defaults on so a business discovers what it needs
         * rather than never finding it. VIP is meaningless until somebody has
         * configured a tier, and most businesses have no membership programme
         * at all — defaulting it on would put an empty screen in every
         * business's navigation. Deliberate exception, not an oversight.
         */
        'default' => false,
        // A membership is held by a contact.
        'requires' => ['customers'],
        'groups' => ['vip'],
        'models' => [VipTier::class, VipMembership::class],
    ],
```

Add `use App\Models\VipMembership;` and `use App\Models\VipTier;` to the imports at the top of that file.

`icon` must exist in `resources/views/components/icon.blade.php`. If `crown` is not defined there, add it alongside the others, or use `spark` which is already present.

- [ ] **Step 7: Add the navigation entry**

In `config/opes.php`, in the `navigation` array after the loyalty-adjacent entries:

```php
        ['key' => 'vip', 'label' => 'VIP members', 'icon' => 'crown', 'route' => 'vip.members', 'ability' => 'vip.view'],
```

- [ ] **Step 8: Verify the module registers and defaults off**

```bash
php artisan tinker --execute='echo json_encode(App\Support\Modules::catalogue()["vip"] ?? "MISSING");'
```

Expected: the entry prints, with `"default":false`.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_19_000001_create_vip_tables.php app/Models/VipTier.php app/Models/VipMembership.php app/Policies/VipMembershipPolicy.php app/Support/Permissions.php config/modules.php config/opes.php
git commit -m "Add the VIP tables, models and module switch

A membership copies its tier's terms rather than referencing them, so raising
Gold's discount next year does not rewrite what an existing member was sold.

isActive() asks the dates rather than trusting the status column: an invoice
raised the day after a membership lapses must get no discount even if the
nightly sweep has not run. The sweep tidies state; it is not what enforces the
rule.

This is the one module that ships switched off. Every other defaults on so a
business discovers what it needs, but VIP is meaningless until a tier exists
and most businesses have no membership programme — on by default would put an
empty screen in everybody's navigation."
```

---

## Task 3: The `VipMemberships` service

**Files:**
- Create: `app/Services/VipMemberships.php`, `database/factories/VipTierFactory.php`, `database/factories/VipMembershipFactory.php`
- Test: `tests/Feature/VipMembershipTest.php`

- [ ] **Step 1: Write the factories**

`database/factories/VipTierFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\VipTier;
use Illuminate\Database\Eloquent\Factories\Factory;

class VipTierFactory extends Factory
{
    protected $model = VipTier::class;

    public function definition(): array
    {
        return [
            'name' => 'Gold',
            'price' => 50000,
            'currency' => 'XAF',
            'period_months' => 12,
            'discount_percent' => 15,
            'perks' => 'Free breakfast, late checkout',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
```

`database/factories/VipMembershipFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\VipMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

class VipMembershipFactory extends Factory
{
    protected $model = VipMembership::class;

    public function definition(): array
    {
        return [
            'tier_name' => 'Gold',
            'discount_percent' => 15,
            'price_paid' => 50000,
            'currency' => 'XAF',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'status' => VipMembership::ACTIVE,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_on' => now()->subYears(2)->toDateString(),
            'ends_on' => now()->subDay()->toDateString(),
        ]);
    }
}
```

Add `use HasFactory;` to both models.

- [ ] **Step 2: Write the failing lifecycle tests**

Create `tests/Feature/VipMembershipTest.php`. Follow the `setUp` pattern in `tests/Feature/DealsPipelineTest.php` — seed `RolePermissionSeeder`, create a company, `joinCompany`, set `CurrentCompany`, and `ChartOfAccounts::seed($company)` since selling posts to the ledger. Enable the module: `$this->company->forceFill(['modules' => ['vip' => true]])->save();`

```php
    public function test_selling_a_membership_raises_an_invoice_and_starts_the_term(): void
    {
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $membership = app(VipMemberships::class)->sell($contact, $tier, $this->owner);

        $this->assertSame(VipMembership::ACTIVE, $membership->status);
        $this->assertSame('Gold', $membership->tier_name);
        $this->assertEquals(15, (float) $membership->discount_percent);
        $this->assertNotNull($membership->document_id);
        $this->assertSame(
            now()->addYear()->toDateString(),
            $membership->ends_on->toDateString()
        );

        // Sold through the ordinary sales path, so the money is in the books.
        $this->assertSame('invoice', $membership->document->type->value);
        $this->assertNotNull($membership->document->number);
    }

    /** Raising the tier's rate must not rewrite what a member was sold. */
    public function test_changing_a_tier_does_not_change_an_existing_membership(): void
    {
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $membership = app(VipMemberships::class)->sell($contact, $tier, $this->owner);

        $tier->update(['discount_percent' => 40, 'price' => 999999]);

        $this->assertEquals(15, (float) $membership->fresh()->discount_percent);
        $this->assertEquals(50000, (float) $membership->fresh()->price_paid);
    }

    /** Nobody loses time they already paid for. */
    public function test_buying_while_active_extends_from_the_current_end_date(): void
    {
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $service = app(VipMemberships::class);
        $first = $service->sell($contact, $tier, $this->owner);
        $second = $service->sell($contact, $tier, $this->owner);

        $this->assertSame(
            $first->ends_on->copy()->addYear()->toDateString(),
            $second->ends_on->toDateString()
        );
        $this->assertSame(VipMembership::EXPIRED, $first->fresh()->status);
    }

    public function test_an_expired_membership_gives_no_discount(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $membership = VipMembership::factory()->expired()->create([
            'company_id' => $this->company->id,
            'contact_id' => $contact->id,
        ]);

        // Still marked active in the column; the dates say otherwise.
        $this->assertSame(0.0, $membership->effectiveDiscount());
        $this->assertSame(0.0, app(VipMemberships::class)->discountFor($contact));
    }

    public function test_the_active_membership_supplies_the_discount(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $contact->id,
        ]);

        $this->assertSame(15.0, app(VipMemberships::class)->discountFor($contact));
    }

    public function test_cancelling_stops_the_benefit_and_keeps_the_record(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);
        $membership = VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $contact->id,
        ]);

        app(VipMemberships::class)->cancel($membership, 'Moved away', $this->owner);

        $this->assertSame(VipMembership::CANCELLED, $membership->fresh()->status);
        $this->assertSame('Moved away', $membership->fresh()->cancelled_reason);
        $this->assertSame(0.0, app(VipMemberships::class)->discountFor($contact));
        $this->assertDatabaseHas('vip_memberships', ['id' => $membership->id]);
    }

    public function test_the_expiry_sweep_marks_lapsed_memberships(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);
        $membership = VipMembership::factory()->expired()->create([
            'company_id' => $this->company->id,
            'contact_id' => $contact->id,
        ]);

        $this->assertSame(1, app(VipMemberships::class)->expireLapsed());
        $this->assertSame(VipMembership::EXPIRED, $membership->fresh()->status);
    }

    public function test_an_inactive_tier_cannot_be_sold(): void
    {
        $tier = VipTier::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $this->expectException(\RuntimeException::class);

        app(VipMemberships::class)->sell($contact, $tier, $this->owner);
    }
```

- [ ] **Step 3: Run them and watch them fail**

```bash
php artisan test --filter=VipMembershipTest
```

Expected: fails — `App\Services\VipMemberships` does not exist.

- [ ] **Step 4: Write the service**

`app/Services/VipMemberships.php`:

```php
<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Models\VipMembership;
use App\Models\VipTier;
use App\Support\CurrentCompany;
use App\Support\Vat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Selling, extending, cancelling and expiring VIP memberships.
 *
 * Selling raises a real invoice through DocumentIssuer rather than recording
 * the money privately: the fee then reaches the ledger, produces a numbered
 * receipt with a verification QR and moves the customer's balance, with no
 * second route into the books for anyone to reconcile later.
 */
class VipMemberships
{
    public function __construct(private readonly DocumentIssuer $issuer) {}

    /**
     * Sell a tier to a contact.
     *
     * An existing live membership is closed and the new term starts where it
     * ended, so nobody loses time they already paid for.
     */
    public function sell(Contact $contact, VipTier $tier, User $actor): VipMembership
    {
        if (! $tier->is_active) {
            throw new RuntimeException('That tier is no longer offered.');
        }

        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot sell a membership without a current company.');
        }

        return DB::transaction(function () use ($contact, $tier, $actor, $company) {
            $current = VipMembership::query()
                ->where('contact_id', $contact->id)
                ->live()
                ->orderByDesc('ends_on')
                ->first();

            $startsOn = $current ? $current->ends_on->copy()->addDay() : now();
            $endsOn = $startsOn->copy()->addMonths($tier->period_months)->subDay();

            if ($current) {
                $current->forceFill(['status' => VipMembership::EXPIRED])->save();
            }

            $document = $this->invoiceFor($contact, $tier, $company, $actor);

            return VipMembership::create([
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'vip_tier_id' => $tier->id,
                // Copied, not referenced — see the migration.
                'tier_name' => $tier->name,
                'discount_percent' => $tier->discount_percent,
                'price_paid' => $tier->price,
                'currency' => $tier->currency,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'status' => VipMembership::ACTIVE,
                'document_id' => $document->id,
                'created_by' => $actor->id,
            ]);
        });
    }

    /** The rate to apply to this contact's invoices today. */
    public function discountFor(?Contact $contact): float
    {
        if ($contact === null) {
            return 0.0;
        }

        $membership = VipMembership::query()
            ->where('contact_id', $contact->id)
            ->live()
            ->orderByDesc('discount_percent')
            ->first();

        return $membership?->effectiveDiscount() ?? 0.0;
    }

    public function cancel(VipMembership $membership, string $reason, User $actor): VipMembership
    {
        $membership->forceFill([
            'status' => VipMembership::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_reason' => $reason,
        ])->save();

        return $membership;
    }

    /**
     * Mark lapsed memberships expired. Returns how many moved.
     *
     * Tidying, not enforcement: `isActive()` already refuses a lapsed
     * membership, so a sweep that has not run cannot hand out a discount.
     */
    public function expireLapsed(): int
    {
        return VipMembership::query()
            ->withoutGlobalScopes()
            ->where('status', VipMembership::ACTIVE)
            ->whereDate('ends_on', '<', now())
            ->update(['status' => VipMembership::EXPIRED]);
    }

    /** The membership fee as an ordinary issued invoice. */
    protected function invoiceFor(Contact $contact, VipTier $tier, $company, User $actor): Document
    {
        $lines = [[
            'description' => 'VIP membership — '.$tier->name.' ('.$tier->period_months.' months)',
            'quantity' => 1,
            'unit_price' => (float) $tier->price,
        ]];

        $vat = Vat::forCompany($company, $lines);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $contact->id,
            'status' => \App\Enums\DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => $company->currency,
            'subtotal' => $vat['subtotal'],
            'discount_total' => $vat['discount_total'],
            'tax_total' => $vat['tax_total'],
            'total' => $vat['total'],
            'amount_paid' => 0,
            'balance' => $vat['total'],
            'created_by' => $actor->id,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => $lines[0]['description'],
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => $lines[0]['unit_price'],
            'tax_amount' => $vat['lines'][0]['tax'],
            'line_total' => $vat['lines'][0]['net'],
            'sort_order' => 0,
        ]);

        return $this->issuer->issue($document->fresh(), $actor);
    }
}
```

- [ ] **Step 5: Run the tests until they pass**

```bash
php artisan test --filter=VipMembershipTest
```

Expected: 8 passed. If `discount_total` is rejected on `Document::create`, confirm the column exists on `documents` (it does) and that the model does not restrict `$fillable` in a way that excludes it.

- [ ] **Step 6: Commit**

```bash
git add app/Services/VipMemberships.php database/factories/VipTierFactory.php database/factories/VipMembershipFactory.php tests/Feature/VipMembershipTest.php app/Models/VipTier.php app/Models/VipMembership.php
git commit -m "Sell a VIP membership through the ordinary sales path

The fee is an invoice raised by DocumentIssuer, not money recorded privately
inside the module — so it reaches the ledger, produces a numbered receipt with
a verification QR and moves the customer's balance, with no second route into
the books for anyone to reconcile.

Buying while already a member extends from the current end date rather than
from today, so nobody loses time they already paid for."
```

---

## Task 4: Apply the discount when an invoice is raised

**Files:**
- Modify: `app/Http/Controllers/Api/DocumentController.php`
- Test: `tests/Feature/VipMembershipTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/VipMembershipTest.php`:

```php
    public function test_an_invoice_for_a_member_carries_the_tier_discount(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);
        VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $contact->id,
        ]);

        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'lines' => [['description' => 'Dinner', 'quantity' => 1, 'unit_price' => 100000]],
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', fn ($v) => (float) $v === 100000.0)
            ->assertJsonPath('data.discount_total', fn ($v) => (float) $v === 15000.0);
    }

    public function test_an_invoice_for_a_non_member_carries_no_discount(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Walk In']);

        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'lines' => [['description' => 'Dinner', 'quantity' => 1, 'unit_price' => 100000]],
        ])->assertCreated()
            ->assertJsonPath('data.discount_total', fn ($v) => (float) $v === 0.0);
    }

    /** The caller decides; the tier only proposes. */
    public function test_an_explicit_discount_overrides_the_tier(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);
        VipMembership::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $contact->id,
        ]);

        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'discount_percent' => 0,
            'lines' => [['description' => 'Dinner', 'quantity' => 1, 'unit_price' => 100000]],
        ])->assertCreated()
            ->assertJsonPath('data.discount_total', fn ($v) => (float) $v === 0.0);
    }
```

`DocumentResource` must expose `discount_total`. If it does not, add
`'discount_total' => (float) $this->discount_total,` beside `subtotal`.

- [ ] **Step 2: Run them and watch them fail**

```bash
php artisan test --filter="an_invoice_for_a_member"
```

Expected: fails — `discount_total` comes back as 0.

- [ ] **Step 3: Apply the discount in the controller**

In `app/Http/Controllers/Api/DocumentController.php`, add to the validation rules in `store()`:

```php
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
```

Then replace the line `$vat = Vat::forCompany($company, $data['lines']);` with:

```php
        /*
         * A VIP member's tier proposes a discount; an explicit `discount_percent`
         * in the request overrides it. The tier never decides silently — whoever
         * raises the invoice can always say otherwise, and sending 0 removes it.
         */
        $discountPercent = array_key_exists('discount_percent', $data)
            ? (float) $data['discount_percent']
            : app(VipMemberships::class)->discountFor($contact);

        $vat = Vat::forCompany($company, $data['lines'], $discountPercent);
```

Add `use App\Services\VipMemberships;` to the imports, and include
`'discount_total' => $vat['discount_total'],` in the `Document::create([...])` array.

- [ ] **Step 4: Run the tests**

```bash
php artisan test --filter=VipMembershipTest
```

Expected: 11 passed.

- [ ] **Step 5: Run the whole suite**

```bash
php artisan test
```

Expected: no regressions. `discountFor()` returns 0 when the module is off or the contact has no membership, so existing invoices are unchanged.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/DocumentController.php app/Http/Resources/DocumentResource.php tests/Feature/VipMembershipTest.php
git commit -m "Apply a member's tier discount when an invoice is raised

The tier proposes and the caller decides: an explicit discount_percent in the
request overrides it, and sending zero removes it. The figure is written onto
the document rather than recomputed at render time, so a reprint years later
shows what was actually charged and the content hash the QR verifies covers
it."
```

---

## Task 5: Screens

**Files:**
- Create: `app/Livewire/Vip/Tiers.php`, `resources/views/livewire/vip/tiers.blade.php`, `app/Livewire/Vip/Members.php`, `resources/views/livewire/vip/members.blade.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write the Tiers component**

Follow `app/Livewire/Deals/Form.php` for the shape — `AuthorizesRequests`, `Gate::authorize('vip.manage')` on every write, validation with messages written as sentences.

```php
<?php

namespace App\Livewire\Vip;

use App\Models\VipTier;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Tiers extends Component
{
    use AuthorizesRequests;

    public ?string $editing = null;

    public string $name = '';

    public string $price = '';

    public string $periodMonths = '12';

    public string $discountPercent = '';

    public string $perks = '';

    public function edit(string $id): void
    {
        Gate::authorize('vip.manage');

        $tier = VipTier::findOrFail($id);

        $this->editing = $tier->id;
        $this->name = $tier->name;
        $this->price = (string) $tier->price;
        $this->periodMonths = (string) $tier->period_months;
        $this->discountPercent = (string) $tier->discount_percent;
        $this->perks = (string) $tier->perks;
    }

    public function save(): void
    {
        Gate::authorize('vip.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'periodMonths' => ['required', 'integer', 'min:1', 'max:120'],
            'discountPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'perks' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'Give the tier a name your customers will recognise.',
            'discountPercent.max' => 'A discount cannot be more than the whole bill.',
        ]);

        $attributes = [
            'name' => trim($data['name']),
            'price' => $data['price'],
            'period_months' => $data['periodMonths'],
            'discount_percent' => $data['discountPercent'],
            'perks' => $data['perks'] ?: null,
        ];

        $this->editing
            ? VipTier::findOrFail($this->editing)->update($attributes)
            : VipTier::create($attributes + ['currency' => 'XAF', 'is_active' => true]);

        $this->reset(['editing', 'name', 'price', 'discountPercent', 'perks']);
        $this->periodMonths = '12';

        session()->flash('status', 'Tier saved.');
    }

    /** Withdrawn, not deleted: memberships sold from it keep their history. */
    public function withdraw(string $id): void
    {
        Gate::authorize('vip.manage');

        VipTier::findOrFail($id)->update(['is_active' => false]);

        session()->flash('status', 'Tier withdrawn. Existing members keep what they bought.');
    }

    public function render(): View
    {
        $this->authorize('viewAny', \App\Models\VipMembership::class);

        return view('livewire.vip.tiers', [
            'tiers' => VipTier::orderBy('sort_order')->orderBy('name')->get(),
        ])->layout('components.layouts.app', ['title' => 'VIP tiers', 'active' => 'vip']);
    }
}
```

- [ ] **Step 2: Write the Tiers view**

`resources/views/livewire/vip/tiers.blade.php`. Copy the page shell from `resources/views/livewire/deals/form.blade.php` — the `px-5 pb-8 lg:px-6 lg:pt-6` wrapper, `x-ui.panel`, the `control` input classes and the `tap focusable` button classes. The page needs: a heading, a flash message block reading `session('status')`, a form with the five fields bound to the properties above, and a list of existing tiers each showing name, price, period, discount and a Withdraw button guarded by `@can('vip.manage')`.

- [ ] **Step 3: Write the Members component**

```php
<?php

namespace App\Livewire\Vip;

use App\Models\Contact;
use App\Models\VipMembership;
use App\Models\VipTier;
use App\Services\VipMemberships;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class Members extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $filter = 'active';

    public ?string $sellTo = null;

    public ?string $sellTier = null;

    public function sell(VipMemberships $service): void
    {
        Gate::authorize('vip.sell');

        $this->validate([
            'sellTo' => ['required', 'string', 'exists:contacts,id'],
            'sellTier' => ['required', 'string', 'exists:vip_tiers,id'],
        ], [
            'sellTo.required' => 'Choose the customer joining.',
            'sellTier.required' => 'Choose the tier they are buying.',
        ]);

        try {
            $service->sell(
                Contact::findOrFail($this->sellTo),
                VipTier::findOrFail($this->sellTier),
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('sellTier', $e->getMessage());

            return;
        }

        $this->reset(['sellTo', 'sellTier']);

        session()->flash('status', 'Membership sold and invoiced.');
    }

    public function cancel(string $id, VipMemberships $service): void
    {
        Gate::authorize('vip.manage');

        $service->cancel(VipMembership::findOrFail($id), 'Cancelled from the members screen', auth()->user());

        session()->flash('status', 'Membership cancelled.');
    }

    public function render(): View
    {
        $this->authorize('viewAny', VipMembership::class);

        return view('livewire.vip.members', [
            'memberships' => VipMembership::query()
                ->with('contact')
                ->when($this->filter === 'active', fn (Builder $q) => $q->live())
                ->when($this->filter === 'expired', fn (Builder $q) => $q->whereDate('ends_on', '<', now()))
                ->latest('ends_on')
                ->paginate(20),
            'tiers' => VipTier::where('is_active', true)->orderBy('name')->get(),
            'contacts' => Contact::where('type', 'customer')->orderBy('name')->get(['id', 'name']),
        ])->layout('components.layouts.app', ['title' => 'VIP members', 'active' => 'vip']);
    }
}
```

- [ ] **Step 4: Write the Members view**

`resources/views/livewire/vip/members.blade.php`. Same shell. Needs: filter chips for active/expired/all bound to `filter` (copy the chip markup from `resources/views/livewire/customers/index.blade.php`), a sell form with two selects and a button guarded by `@can('vip.sell')`, and the member list showing customer name (linked to `route('customers.show', ...)`), tier, expiry date and a Cancel button guarded by `@can('vip.manage')`.

- [ ] **Step 5: Add the routes**

In `routes/web.php`, beside the other module routes:

```php
    Route::get('/vip', VipMembers::class)->middleware('can:vip.view')->name('vip.members');
    Route::get('/vip/tiers', VipTiers::class)->middleware('can:vip.view')->name('vip.tiers');
```

With imports `use App\Livewire\Vip\Members as VipMembers;` and `use App\Livewire\Vip\Tiers as VipTiers;`.

- [ ] **Step 6: Test the screens**

Append to `tests/Feature/VipMembershipTest.php`:

```php
    public function test_the_members_screen_renders_and_sells(): void
    {
        VipTier::factory()->create(['company_id' => $this->company->id]);
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Vip\Members::class)
            ->set('sellTo', $contact->id)
            ->set('sellTier', VipTier::first()->id)
            ->call('sell')
            ->assertHasNoErrors();

        $this->assertSame(1, VipMembership::count());
    }

    public function test_a_cashier_cannot_sell_a_membership(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($cashier)->get(route('vip.members'))->assertForbidden();
    }

    public function test_the_screen_closes_when_the_module_is_off(): void
    {
        $this->company->forceFill(['modules' => ['vip' => false]])->save();

        $this->actingAs($this->owner)->get(route('vip.members'))->assertForbidden();
    }
```

```bash
php artisan test --filter=VipMembershipTest
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Vip resources/views/livewire/vip routes/web.php tests/Feature/VipMembershipTest.php
git commit -m "Add the VIP tiers and members screens

A tier is withdrawn rather than deleted: memberships sold from it keep the
history of what they were sold under."
```

---

## Task 6: API and webhooks

**Files:**
- Create: `app/Http/Controllers/Api/VipController.php`, `app/Http/Resources/VipTierResource.php`, `app/Http/Resources/VipMembershipResource.php`
- Modify: `routes/api.php`, `app/Support/WebhookEvents.php`, `app/Services/VipMemberships.php`
- Test: `tests/Feature/VipApiTest.php`

- [ ] **Step 1: Write the resources**

`app/Http/Resources/VipTierResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VipTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'period_months' => (int) $this->period_months,
            'discount_percent' => (float) $this->discount_percent,
            'perks' => $this->perks,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
```

`app/Http/Resources/VipMembershipResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The terms are the membership's own, not the tier's — a tier repriced since
 * the sale must not change what this member is reported as holding.
 */
class VipMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'contact_name' => $this->whenLoaded('contact', fn () => $this->contact?->name),
            'tier_name' => $this->tier_name,
            'discount_percent' => (float) $this->discount_percent,
            'price_paid' => (float) $this->price_paid,
            'currency' => $this->currency,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'document_id' => $this->document_id,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_reason' => $this->cancelled_reason,
        ];
    }
}
```

- [ ] **Step 2: Write the controller**

`app/Http/Controllers/Api/VipController.php`, following `PartnerController`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\VipMembershipResource;
use App\Http\Resources\VipTierResource;
use App\Models\Contact;
use App\Models\VipMembership;
use App\Models\VipTier;
use App\Services\VipMemberships;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class VipController extends ApiController
{
    public function __construct(private readonly VipMemberships $service) {}

    public function tiers(): AnonymousResourceCollection
    {
        $this->authorize('vip.view');

        return VipTierResource::collection(
            VipTier::orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function storeTier(Request $request): JsonResponse
    {
        $this->authorize('vip.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'period_months' => ['required', 'integer', 'min:1', 'max:120'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'perks' => ['nullable', 'string', 'max:2000'],
        ]);

        $tier = VipTier::create($data + ['currency' => 'XAF', 'is_active' => true]);

        return VipTierResource::make($tier)->response()->setStatusCode(201);
    }

    public function memberships(Request $request): AnonymousResourceCollection
    {
        $this->authorize('vip.view');

        $filters = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'contact_id' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $memberships = VipMembership::query()
            ->with('contact')
            ->when($filters['active'] ?? false, fn (Builder $q) => $q->live())
            ->when(isset($filters['contact_id']), fn (Builder $q) => $q->where('contact_id', $filters['contact_id']))
            ->latest('ends_on')
            ->paginate($filters['per_page'] ?? 25);

        return VipMembershipResource::collection($memberships);
    }

    /** Selling takes money, so this sits under the `money` scope. */
    public function sell(Request $request): JsonResponse
    {
        $this->authorize('vip.sell');

        $data = $request->validate([
            'contact_id' => ['required', 'string', 'exists:contacts,id'],
            'tier_id' => ['required', 'string', 'exists:vip_tiers,id'],
        ]);

        try {
            $membership = $this->service->sell(
                Contact::findOrFail($data['contact_id']),
                VipTier::findOrFail($data['tier_id']),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return VipMembershipResource::make($membership->load('contact'))
            ->response()->setStatusCode(201);
    }

    public function cancel(Request $request, VipMembership $membership): VipMembershipResource
    {
        $this->authorize('vip.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Say why this membership is being cancelled.',
        ]);

        $this->service->cancel($membership, $data['reason'], $request->user());

        return VipMembershipResource::make($membership->fresh()->load('contact'));
    }
}
```

- [ ] **Step 3: Add the routes**

In `routes/api.php`, inside the `ability:read` group:

```php
            Route::get('vip/tiers', [VipController::class, 'tiers'])->name('api.v1.vip.tiers');
            Route::get('vip/memberships', [VipController::class, 'memberships'])->name('api.v1.vip.memberships');
```

Inside `ability:write`:

```php
            Route::post('vip/tiers', [VipController::class, 'storeTier'])->name('api.v1.vip.tiers.store');
            Route::post('vip/memberships/{membership}/cancel', [VipController::class, 'cancel'])->name('api.v1.vip.memberships.cancel');
```

Inside the `['ability:money', 'idempotent']` group:

```php
            // Selling charges the customer, so a retry after a dropped
            // connection must not sell and charge for two memberships.
            Route::post('vip/memberships', [VipController::class, 'sell'])->name('api.v1.vip.memberships.sell');
```

Add `use App\Http\Controllers\Api\VipController;` to the imports.

- [ ] **Step 4: Add the webhook events**

In `app/Support/WebhookEvents.php`, add three entries to the catalogue following the existing format:

```php
        'vip.membership.sold' => 'A VIP membership was sold',
        'vip.membership.cancelled' => 'A VIP membership was cancelled',
        'vip.membership.expired' => 'A VIP membership reached its end date',
```

In `VipMemberships::sell()`, before returning the membership, dispatch:

```php
            app(WebhookDispatcher::class)->send('vip.membership.sold', [
                'id' => $membership->id,
                'contact_id' => $contact->id,
                'tier_name' => $membership->tier_name,
                'discount_percent' => (float) $membership->discount_percent,
                'price_paid' => (float) $membership->price_paid,
                'starts_on' => $membership->starts_on->toDateString(),
                'ends_on' => $membership->ends_on->toDateString(),
            ], $company);
```

Assign the created membership to `$membership` first so it can be referenced. Do the same for `cancel()` with `vip.membership.cancelled`.

- [ ] **Step 5: Write the API tests**

`tests/Feature/VipApiTest.php`, following `tests/Feature/PartnerApiTest.php`:

```php
    public function test_a_membership_can_be_sold_over_the_api(): void
    {
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $this->postJson('/api/v1/vip/memberships', [
            'contact_id' => $contact->id,
            'tier_id' => $tier->id,
        ])->assertCreated()
            ->assertJsonPath('data.tier_name', 'Gold')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_selling_twice_with_one_key_sells_once(): void
    {
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $body = ['contact_id' => $contact->id, 'tier_id' => $tier->id];

        $first = $this->postJson('/api/v1/vip/memberships', $body, $key)->assertCreated();

        $this->postJson('/api/v1/vip/memberships', $body, $key)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, VipMembership::count());
    }

    public function test_a_read_token_cannot_sell(): void
    {
        Sanctum::actingAs($this->owner, [\App\Support\TokenAbilities::READ]);

        $this->getJson('/api/v1/vip/tiers')->assertOk();
        $this->postJson('/api/v1/vip/memberships', [])->assertForbidden();
    }

    public function test_another_companys_memberships_are_not_reachable(): void
    {
        $stranger = User::factory()->create();
        $other = $this->makeCompany('Other Sarl', $stranger);

        $theirContact = Contact::withoutGlobalScopes()->create([
            'company_id' => $other->id, 'type' => 'customer', 'name' => 'Theirs',
        ]);
        $theirs = VipMembership::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'contact_id' => $theirContact->id,
            'tier_name' => 'Gold', 'discount_percent' => 15, 'price_paid' => 50000,
            'currency' => 'XAF',
            'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(),
            'status' => VipMembership::ACTIVE,
        ]);

        $this->getJson('/api/v1/vip/memberships')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/vip/memberships/{$theirs->id}/cancel", ['reason' => 'no'])
            ->assertNotFound();
    }
```

```bash
php artisan test --filter=VipApiTest
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/VipController.php app/Http/Resources/VipTierResource.php app/Http/Resources/VipMembershipResource.php app/Support/WebhookEvents.php app/Services/VipMemberships.php routes/api.php tests/Feature/VipApiTest.php
git commit -m "Put VIP on the API

Selling sits under the money scope and takes an Idempotency-Key: a retry after
a dropped connection must not sell and charge for two memberships."
```

---

## Task 7: The expiry sweep

**Files:**
- Create: `app/Console/Commands/ExpireVipMemberships.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\VipMemberships;
use Illuminate\Console\Command;

/**
 * Marks lapsed memberships expired.
 *
 * Housekeeping, not enforcement. `VipMembership::isActive()` already refuses a
 * membership past its end date, so a night when this does not run cannot hand
 * anybody a discount they are no longer entitled to — the screens and reports
 * simply show a stale status until it does.
 */
class ExpireVipMemberships extends Command
{
    protected $signature = 'opes:expire-vip-memberships';

    protected $description = 'Mark VIP memberships past their end date as expired';

    public function handle(VipMemberships $memberships): int
    {
        $count = $memberships->expireLapsed();

        $this->info($count === 0
            ? 'No memberships to expire.'
            : sprintf('Expired %d membership%s.', $count, $count === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Schedule it**

In `routes/console.php`:

```php
Schedule::command('opes:expire-vip-memberships')->dailyAt('00:30');
```

- [ ] **Step 3: Test it**

Append to `tests/Feature/VipMembershipTest.php`:

```php
    public function test_the_expiry_command_runs(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);
        VipMembership::factory()->expired()->create([
            'company_id' => $this->company->id,
            'contact_id' => $contact->id,
        ]);

        $this->artisan('opes:expire-vip-memberships')
            ->expectsOutputToContain('Expired 1 membership.')
            ->assertSuccessful();
    }
```

```bash
php artisan test --filter=the_expiry_command_runs
```

Expected: passes.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ExpireVipMemberships.php routes/console.php tests/Feature/VipMembershipTest.php
git commit -m "Sweep lapsed VIP memberships nightly

Housekeeping, not enforcement: isActive() already refuses a membership past its
end date, so a night the sweep does not run cannot hand out a discount."
```

---

## Task 8: The membership card

The spec promises a printable card that verifies by QR, and Task 2 created the
columns for it. This issues and prints one, copying the loyalty card pattern
rather than inventing a second one.

**Files:**
- Modify: `app/Services/VipMemberships.php`, `app/Http/Controllers/PrintController.php`, `routes/web.php`
- Create: `resources/views/print/vip-card.blade.php`
- Test: `tests/Feature/VipMembershipTest.php` (append)

- [ ] **Step 1: Write the failing test**

```php
    public function test_a_sold_membership_gets_a_verifiable_card(): void
    {
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $membership = app(VipMemberships::class)->sell($contact, $tier, $this->owner);

        $this->assertNotNull($membership->card_number);
        $this->assertStringStartsWith('VIP-', $membership->card_number);
        $this->assertNotNull($membership->verification_token_id);
    }

    public function test_the_card_prints(): void
    {
        $tier = VipTier::factory()->create(['company_id' => $this->company->id]);
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $membership = app(VipMemberships::class)->sell($contact, $tier, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('vip.card.print', $membership))
            ->assertOk()
            ->assertSee('Nodai Felix')
            ->assertSee('Gold')
            ->assertSee($membership->card_number);
    }
```

```bash
php artisan test --filter="verifiable_card|the_card_prints"
```

Expected: fails — no card number is issued and the route does not exist.

- [ ] **Step 2: Issue the card when selling**

In `app/Services/VipMemberships.php`, add these imports:

```php
use App\Models\VerificationToken;
use Illuminate\Support\Str;
```

Add the method:

```php
    /**
     * A card number and the token its QR resolves to.
     *
     * The same shape as the loyalty card: a prefix so a number read down a
     * phone line is recognisable, and a verification token so a printed card
     * can be checked against the business by somebody with no account.
     */
    protected function issueCard(VipMembership $membership): void
    {
        $token = VerificationToken::create([
            'company_id' => $membership->company_id,
            'token' => VerificationToken::newToken(),
            'subject_type' => VipMembership::class,
            'subject_id' => $membership->id,
        ]);

        do {
            $number = 'VIP-'.Str::upper(Str::random(8));
        } while (VipMembership::withoutGlobalScopes()
            ->where('company_id', $membership->company_id)
            ->where('card_number', $number)
            ->exists());

        $membership->forceFill([
            'card_number' => $number,
            'verification_token_id' => $token->id,
        ])->save();
    }
```

In `sell()`, after the membership is created and before the webhook is
dispatched, call `$this->issueCard($membership);` then `$membership->refresh();`.

- [ ] **Step 3: Add the print action**

In `app/Http/Controllers/PrintController.php`, following `loyaltyCard()` at line 154:

```php
    public function vipCard(VipMembership $membership, QrCodes $qr)
    {
        $company = app(CurrentCompany::class)->get();
        abort_if($company === null, 404);

        $membership->load('contact', 'verificationToken');

        return view('print.vip-card', [
            'membership' => $membership,
            'company' => $company,
            'qrSvg' => $membership->verificationToken
                ? $qr->svg($membership->verificationToken->publicUrl(), 120)
                : null,
        ]);
    }
```

Add `use App\Models\VipMembership;` to the imports. Add the relation to
`VipMembership`:

```php
    public function verificationToken(): BelongsTo
    {
        return $this->belongsTo(VerificationToken::class);
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, beside the other VIP routes:

```php
    Route::get('/vip/{membership}/card/print', [PrintController::class, 'vipCard'])
        ->middleware('can:vip.view')->name('vip.card.print');
```

- [ ] **Step 5: Write the card view**

`resources/views/print/vip-card.blade.php`. Copy the structure and card
dimensions from `resources/views/print/loyalty-card.blade.php` — same
85.6 × 54 mm credit-card size, same self-contained CSS, same print bar. It must
show the company letterhead logo via `$company->logoUrl()`, the member's name,
the tier name, the expiry date, the card number and the QR.

- [ ] **Step 6: Run the tests**

```bash
php artisan test --filter=VipMembershipTest
```

Expected: all pass.

- [ ] **Step 7: Add a print link to the members screen**

In `resources/views/livewire/vip/members.blade.php`, on each row:

```blade
<a href="{{ route('vip.card.print', $membership) }}" target="_blank"
   class="focusable text-[13px] font-semibold text-brand hover:underline">Card</a>
```

- [ ] **Step 8: Commit**

```bash
git add app/Services/VipMemberships.php app/Http/Controllers/PrintController.php app/Models/VipMembership.php routes/web.php resources/views/print/vip-card.blade.php resources/views/livewire/vip/members.blade.php tests/Feature/VipMembershipTest.php
git commit -m "Give a VIP membership a card that verifies

The same shape as the loyalty card rather than a second scheme: a recognisable
prefix so a number read down a phone line is unambiguous, and a verification
token so a printed card can be checked against the business by somebody with
no account."
```

---

## Task 9: Documentation

**Files:**
- Modify: `docs/API.md`, `app/Console/Commands/ExportOpenApi.php`, `database/schema/opes360-install.sql`

- [ ] **Step 1: Add the API docs**

In `docs/API.md`, add a section before "What is not here yet", covering: the endpoints and their scopes, that selling raises a real invoice, that the membership carries the terms it was sold under rather than the tier's current ones, and that the tier discount is proposed and can be overridden with `discount_percent` on the document endpoint.

Then update the "What is not here yet" section: VIP is now covered.

- [ ] **Step 2: Add the OpenAPI summaries**

In `app/Console/Commands/ExportOpenApi.php`, add to `$summaries`:

```php
        'vip.tiers' => 'The membership tiers this business offers.',
        'vip.tiers.store' => 'Add a tier.',
        'vip.memberships' => 'Memberships sold. Filter with active=1.',
        'vip.memberships.sell' => 'Sell a membership. Raises a real invoice and starts the term.',
        'vip.memberships.cancel' => 'Cancel a membership, with a reason.',
```

And to `$idempotent`:

```php
        'vip.memberships.sell',
```

- [ ] **Step 3: Regenerate the spec and the install schema**

```bash
php artisan opes:export-openapi
```

Expected: no "No summary for" warning mentioning vip.

```powershell
# PowerShell — needs mysqldump on PATH
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;$env:PATH"
php artisan opes:export-schema
```

- [ ] **Step 4: Run the whole suite**

```bash
php artisan test
```

Expected: everything passes, including `InstallSchemaIsCurrentTest` and `OpenApiSpecTest`.

- [ ] **Step 5: Commit**

```bash
git add docs/API.md app/Console/Commands/ExportOpenApi.php public/openapi.json database/schema/opes360-install.sql
git commit -m "Document the VIP module"
```

---

## Definition of done

- [ ] `php artisan test` passes in full.
- [ ] A tier can be created, a membership sold, and the invoice appears in Sales with the fee on it.
- [ ] An invoice for an active member shows the discount as its own figure, with TVA computed on the discounted base.
- [ ] The same invoice for a non-member, or a lapsed member, shows no discount.
- [ ] Raising a tier's discount leaves existing memberships untouched.
- [ ] The module switched off closes every screen and route.
- [ ] `/openapi.json` lists the VIP endpoints with their scopes.
