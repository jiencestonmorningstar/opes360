<?php

namespace App\Support;

use App\Models\Company;

/**
 * The sector question at signup, and what each answer switches on and off.
 *
 * ── Why this exists ──────────────────────────────────────────────────────
 *
 * The module catalogue defaults almost everything on so a business discovers
 * what it needs (see config/modules.php's header) — but a business that just
 * told us it brokers insurance has already answered the discovery question.
 * An insurance broker has no business with manufacturing; a property agency
 * none with stock. The sector turns the right verticals on and the obviously
 * irrelevant defaults off, once, at creation.
 *
 * ── The sector guides, it does not lock ──────────────────────────────────
 *
 * A sector is a starting point, nothing more. Settings → Modules remains the
 * one source of truth, and nothing ever re-reads the sector to overrule a
 * switch somebody set there — the only later use of the stored slug is the
 * explicit "reset to sector defaults" action, which the person asks for.
 * That promise is pinned by a test.
 *
 * ── How the answer is stored ─────────────────────────────────────────────
 *
 * The `modules` json stores departures from the catalogue's defaults (see
 * ModulesTest: a module the business has never heard of arrives switched on).
 * A sector therefore writes explicit true/false entries for exactly the keys
 * it takes a view on, and stays silent about everything else — so a module
 * added to the catalogue next year still arrives switched on for a business
 * that picked its sector this year.
 *
 * Each entry lists `on` (modules to enable beyond the defaults) and `off`
 * (default-on modules this sector has no use for). `modulesFor()` closes the
 * `on` list over the catalogue's `requires` chains, so a sector can never
 * yield a module missing its requirements.
 */
class Sectors
{
    /**
     * The default when the question is skipped: today's behaviour, untouched.
     */
    public const EVERYTHING = 'everything';

    /** @return array<string, array{label: string, description: string, on: array<int, string>, off: array<int, string>}> */
    public static function catalogue(): array
    {
        return [
            'retail' => [
                'label' => 'Retail & trade',
                'description' => 'A shop or trading business: sales, stock and customer orders.',
                'on' => ['orders'],
                // A shop sells over the counter; it does not run a deal
                // pipeline, a project budget or a contract register.
                'off' => ['projects', 'deals', 'contracts'],
            ],

            'services' => [
                'label' => 'Services & consulting',
                'description' => 'Selling time and expertise: projects, contracts, no stock.',
                'on' => [],
                // No products means no stock; stock locations cascade off with
                // them through the catalogue's own `requires` resolution.
                'off' => ['products', 'events', 'loyalty'],
            ],

            'secretariat' => [
                'label' => 'Secretariat & print bureau',
                'description' => 'Documents, forms and print jobs for walk-in clients.',
                'on' => [],
                // One counter, one shelf: multiple stock locations are noise.
                'off' => ['stock_locations'],
            ],

            'insurance_broker' => [
                'label' => 'Insurance broker',
                'description' => 'Policies, claims and the commissions insurers owe you.',
                'on' => ['insurance'],
                // A broker sells cover, not goods — no products, and with them
                // no stock; premiums are invoices, so sales stays on.
                'off' => ['products', 'projects', 'events', 'loyalty'],
            ],

            'distribution' => [
                'label' => 'Distributor & wholesaler',
                'description' => 'Customer orders against stock, supplier sourcing and payment runs.',
                'on' => ['orders', 'payables', 'procurement'],
                'off' => ['projects', 'events', 'loyalty'],
            ],

            'logistics' => [
                'label' => 'Transporter & logistics',
                'description' => 'Shipments, trip manifests and proof of delivery. The fleet lives in fixed assets.',
                'on' => ['logistics'],
                // A transporter moves other people's goods: no product book of
                // its own. Assets stay on — the trucks are the business.
                'off' => ['products', 'events', 'loyalty'],
            ],

            'estate' => [
                'label' => 'Real-estate agency',
                'description' => 'Properties, tenancies, rent that invoices itself. No stock, no lot-tracking.',
                'on' => ['estate'],
                // Estate requires customers, contracts and sales — all default
                // on and deliberately left untouched here.
                'off' => ['products', 'events', 'loyalty'],
            ],

            'manufacturing' => [
                'label' => 'Workshop & manufacturer',
                'description' => 'Recipes, production orders, sourcing parts and fulfilling orders.',
                'on' => ['manufacturing', 'orders', 'procurement'],
                'off' => ['events', 'loyalty'],
            ],

            'health' => [
                'label' => 'Clinic & pharmacy',
                'description' => 'Stock with batch and expiry (lot) tracking, per product, stays on.',
                'on' => [],
                // Lot tracking is a per-product setting inside Products &
                // stock, which is exactly why products must not go anywhere.
                'off' => ['deals', 'events'],
            ],

            'school' => [
                'label' => 'School',
                'description' => 'Fees as invoices, staff and payroll, school events with tickets.',
                'on' => [],
                'off' => ['products', 'deals', 'loyalty'],
            ],

            'ngo' => [
                'label' => 'NGO & projects',
                'description' => 'Project budgets, compliance and the money trail funders ask about.',
                'on' => [],
                'off' => ['products', 'deals', 'events', 'loyalty'],
            ],

            self::EVERYTHING => [
                'label' => 'A bit of everything',
                'description' => 'Start with the standard set and prune later in Settings.',
                'on' => [],
                'off' => [],
            ],
        ];
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::catalogue());
    }

    public static function label(string $slug): string
    {
        return self::catalogue()[$slug]['label'] ?? self::catalogue()[self::EVERYTHING]['label'];
    }

    /**
     * The departures-from-default the sector writes: explicit true/false for
     * exactly the keys it takes a view on, closed over `requires`.
     *
     * The closure only touches a requirement that would otherwise be off —
     * one that defaults off, or one this sector's own `off` list contradicts.
     * A requirement that is on by default and untouched stays unwritten, so
     * the stored json remains a minimal set of departures.
     */
    public static function modulesFor(string $slug): array
    {
        $entry = self::catalogue()[$slug] ?? self::catalogue()[self::EVERYTHING];

        $map = array_fill_keys($entry['off'], false);

        $enable = function (string $key) use (&$enable, &$map): void {
            $map[$key] = true;

            foreach ((array) (Modules::catalogue()[$key]['requires'] ?? []) as $needed) {
                $default = (bool) (Modules::catalogue()[$needed]['default'] ?? true);

                // On wins over off: a sector that enables a vertical cannot at
                // the same time starve it of what it needs to work.
                if (($map[$needed] ?? $default) !== true) {
                    $enable($needed);
                }
            }
        };

        foreach ($entry['on'] as $key) {
            $enable($key);
        }

        return $map;
    }

    /**
     * Apply a sector to a company: record the answer, write the departures.
     *
     * Called exactly once, at creation, inside the caller's transaction. The
     * merge keeps any departures already stored (there are none at signup,
     * but the demo provisioner seeds content first) with the sector's own
     * taking precedence, and `everything` writes no departures at all.
     */
    public static function apply(Company $company, string $slug): void
    {
        $slug = self::exists($slug) ? $slug : self::EVERYTHING;

        $company->forceFill([
            'sector' => $slug,
            'modules' => array_merge((array) ($company->modules ?? []), self::modulesFor($slug)),
        ])->save();

        Modules::flush();
    }
}
