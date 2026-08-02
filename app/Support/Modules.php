<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * Which modules a business has switched on, and what that means for an ability.
 *
 * The catalogue lives in config/modules.php; this answers questions about it.
 * A company stores only its *departures* from the defaults, so a module added
 * to the catalogue in a later release arrives switched on for every existing
 * business rather than silently missing for all of them.
 */
class Modules
{
    /** Resolved answers for the life of the request, keyed by company. */
    protected static array $cache = [];

    /** @return array<string, array<string, mixed>> the whole catalogue */
    public static function catalogue(): array
    {
        return (array) config('modules', []);
    }

    /** The ones a business may actually switch, in catalogue order. */
    public static function switchable(): array
    {
        return array_filter(self::catalogue(), fn (array $m) => ($m['switchable'] ?? true) === true);
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::catalogue());
    }

    /**
     * Is this module on for this business?
     *
     * A module whose requirements are off is off, whatever its own setting
     * says — payroll with no staff records is a screen that cannot work, and
     * leaving it reachable would be a bug report rather than a feature.
     */
    public static function enabled(Company $company, string $key): bool
    {
        return in_array($key, self::enabledFor($company), true);
    }

    /** @return array<int, string> */
    public static function enabledFor(Company $company): array
    {
        $cacheKey = $company->id.':'.md5(json_encode($company->modules ?? []));

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $settings = (array) ($company->modules ?? []);
        $on = [];

        foreach (self::catalogue() as $key => $module) {
            // Not switchable means not switchable off, either.
            if (($module['switchable'] ?? true) === false) {
                $on[$key] = true;

                continue;
            }

            $on[$key] = array_key_exists($key, $settings)
                ? (bool) $settings[$key]
                : (bool) ($module['default'] ?? true);
        }

        // Requirements cascade, and can chain — resolve until it settles
        // rather than assuming the catalogue is ordered parents-first.
        do {
            $changed = false;

            foreach (self::catalogue() as $key => $module) {
                if (! $on[$key]) {
                    continue;
                }

                foreach ((array) ($module['requires'] ?? []) as $needed) {
                    if (! ($on[$needed] ?? false)) {
                        $on[$key] = false;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return self::$cache[$cacheKey] = array_keys(array_filter($on));
    }

    /**
     * The module an ability belongs to, or null when nothing owns it.
     *
     * Nothing owning it is the normal case for the account's own abilities —
     * business, users, devices, settings — which is why they can never be
     * switched off by accident.
     */
    public static function forAbility(string $ability): ?string
    {
        $group = explode('.', $ability, 2)[0] ?? '';

        foreach (self::catalogue() as $key => $module) {
            if (in_array($ability, (array) ($module['abilities'] ?? []), true)) {
                return $key;
            }
        }

        foreach (self::catalogue() as $key => $module) {
            if (in_array($ability, (array) ($module['except'] ?? []), true)) {
                continue;
            }

            if (in_array($group, (array) ($module['groups'] ?? []), true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The module a model belongs to.
     *
     * Needed because a policy check asks `can:view` with a Document, not
     * `can:sales.view` — without this map a disabled module's detail pages
     * would still answer on their direct URLs.
     */
    public static function forModel(Model|string $model): ?string
    {
        $class = is_string($model) ? $model : $model::class;

        foreach (self::catalogue() as $key => $module) {
            foreach ((array) ($module['models'] ?? []) as $mapped) {
                if ($class === $mapped || is_subclass_of($class, $mapped)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** Whether a company may use an ability, module-wise. Says nothing about roles. */
    public static function allowsAbility(Company $company, string $ability): bool
    {
        $module = self::forAbility($ability);

        return $module === null || self::enabled($company, $module);
    }

    public static function label(string $key): string
    {
        return self::catalogue()[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** What else switches off with this one, for a confirmation that tells the truth. */
    public static function dependents(string $key): array
    {
        $out = [];

        foreach (self::catalogue() as $other => $module) {
            if (in_array($key, (array) ($module['requires'] ?? []), true)) {
                $out[] = $other;
                $out = array_merge($out, self::dependents($other));
            }
        }

        return array_values(array_unique($out));
    }

    /** Tests and long-lived processes change a company's settings under us. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
