<?php

namespace App\Services\Documents;

use App\Models\Company;

/**
 * The dynamic fields a document template can use — §7 of the master spec.
 *
 * "Do not hard-code these fields," it says, and the earlier version of
 * DocumentComposer did exactly that: `company.name`, `company.address` and
 * the rest were written straight into automaticValues(). Every one of those
 * moved here as the registry's own default provider, so no field a business
 * template can already use disappeared — only where it comes from changed.
 *
 * A provider is a closure: given the company and, when composing already
 * knows one, a named context of related records (`customer` => a Contact,
 * `employee` => an Employee, `project` => a Project), it returns flat
 * `prefix.field => value` pairs. Any module can add one:
 *
 *     app(DocumentFieldRegistry::class)->register('customer', function ($company, array $context) {
 *         $customer = $context['customer'] ?? null;
 *         return $customer ? ['customer.name' => $customer->name] : [];
 *     });
 *
 * Registered once, in a service provider's boot(), the same way workflow and
 * automation listeners are registered — never scattered across the modules
 * that happen to call DocumentComposer.
 */
class DocumentFieldRegistry
{
    /** @var array<string, callable(Company, array<string, mixed>): array<string, mixed>> */
    protected array $providers = [];

    /** @param  callable(Company, array<string, mixed>): array<string, mixed>  $provider */
    public function register(string $prefix, callable $provider): void
    {
        $this->providers[$prefix] = $provider;
    }

    public function has(string $prefix): bool
    {
        return array_key_exists($prefix, $this->providers);
    }

    /** @return array<int, string> */
    public function prefixes(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Every field every registered provider offers, given what compose-time
     * context is available. A provider that needs a record it was not given
     * simply contributes nothing — it is never an error to compose without a
     * customer in view.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function all(Company $company, array $context = []): array
    {
        $values = [];

        foreach ($this->providers as $provider) {
            $values += $provider($company, $context);
        }

        return $values;
    }
}
