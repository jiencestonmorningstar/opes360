<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * config/modules.php promises that "model-backed policy checks
 * (`can:view,document`) are covered too" — a promise that held for 14 of 56
 * models until Wave 2. One parity test instead of 42 per-policy tests: every
 * model a module claims must exist as a class and resolve to a policy, or the
 * module switch quietly stops guarding that model's detail pages.
 */
class ModulePolicyParityTest extends TestCase
{
    public function test_every_module_model_resolves_to_a_policy(): void
    {
        $models = collect(config('modules'))
            ->flatMap(fn (array $module) => $module['models'] ?? [])
            ->unique()
            ->values();

        $this->assertNotEmpty($models, 'No module lists any models — config/modules.php has rotted.');

        foreach ($models as $model) {
            // Catches the unimported-::class defect: a bare "BankAccount"
            // string means a missing `use` line in config/modules.php.
            $this->assertTrue(
                class_exists($model),
                "config/modules.php lists `{$model}`, which is not a loadable class — "
                .'almost certainly a missing `use App\\Models\\…` import in that file.'
            );

            $this->assertNotNull(
                Gate::getPolicyFor($model),
                "{$model} has no resolvable policy. Add App\\Policies\\".class_basename($model).'Policy '
                .'(see CompanyScopedPolicy / ManagedGroupPolicy for the house shape) so the module '
                .'switch and `can:` middleware can guard its records.'
            );
        }
    }
}
