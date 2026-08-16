<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\Governance;
use App\Models\CompanyUserPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use App\Support\SegregationOfDuties;
use Livewire\Livewire;

/**
 * Who holds what, and which combinations should not sit together.
 */
class SegregationOfDutiesTest extends AuditTestCase
{
    public function test_every_conflict_rule_names_permissions_that_actually_exist(): void
    {
        /*
         * A rule naming a permission that was renamed out of the catalogue
         * fails open — it can never match, so the screen quietly reports "no
         * problems" forever. That is the worst possible failure for a control
         * report, so it is asserted rather than trusted.
         */
        $catalogue = Permissions::slugs();

        foreach (SegregationOfDuties::RULES as $rule) {
            foreach ($rule['abilities'] as $ability) {
                $this->assertContains($ability, $catalogue, "SoD rule '{$rule['name']}' names unknown ability {$ability}");
            }
        }
    }

    public function test_it_reports_a_person_who_can_both_approve_and_release_a_payment_run(): void
    {
        $clerk = User::factory()->create(['name' => 'Conflicted Clerk']);
        $this->asRole($clerk, Role::MANAGER);

        $this->grant($clerk, ['payables.approve', 'payables.execute']);

        $findings = SegregationOfDuties::findings($this->company);

        $this->assertTrue(
            $findings->contains(fn (array $f) => $f['user']->is($clerk) && $f['rule']['key'] === 'payables.approve-and-execute')
        );
    }

    public function test_the_seeded_manager_keeps_the_payment_run_halves_apart(): void
    {
        // The seeder's own comment says the Manager gets `payables.manage` but
        // not `approve` or `execute`, "whoever decides which bills go on the
        // list must not be the one who releases the money". If that ever stops
        // being true this test says so.
        $manager = User::factory()->create(['name' => 'Ordinary Manager']);
        $this->asRole($manager, Role::MANAGER);

        $payablesFindings = SegregationOfDuties::findings($this->company)
            ->filter(fn (array $f) => $f['user']->is($manager) && str_starts_with($f['rule']['key'], 'payables.'));

        $this->assertCount(0, $payablesFindings);
    }

    /**
     * The shipped defaults keep operations and bookkeeping apart.
     *
     * This report found a real conflict here once: the Manager role carried
     * both `expenses.create` and `expenses.pay`, so one person could enter
     * what a supplier was owed and settle it — and an invented supplier's
     * bill would have looked exactly like every other bill. It was fixed
     * rather than accepted, and the same reasoning removed `payroll.run`
     * from a role that can also create employees.
     *
     * A manager runs the business. An accountant keeps its books. If a
     * business is small enough to need one person doing both, that is a
     * grant somebody makes deliberately and the report will show it.
     */
    public function test_the_seeded_manager_holds_no_conflicting_duties(): void
    {
        $manager = User::factory()->create(['name' => 'Ordinary Manager']);
        $this->asRole($manager, Role::MANAGER);

        $findings = SegregationOfDuties::findings($this->company)
            ->filter(fn (array $f) => $f['user']->is($manager));

        $this->assertCount(0, $findings, sprintf(
            'The seeded Manager role has picked up a conflict: %s',
            $findings->map(fn (array $f) => $f['rule']['key'])->implode(', ')
        ));
    }

    public function test_the_owner_is_reported_separately_rather_than_as_a_violation(): void
    {
        /*
         * The Owner holds everything by design and would otherwise trip every
         * rule, drowning the real findings. Listing them apart keeps the fact
         * visible — an owner who does the books alone genuinely has no
         * separation — without making the report useless.
         */
        $findings = SegregationOfDuties::findings($this->company);
        $expected = SegregationOfDuties::unavoidable($this->company);

        $this->assertFalse($findings->contains(fn (array $f) => $f['user']->is($this->user)));
        $this->assertTrue($expected->contains(fn (array $f) => $f['user']->is($this->user)));
    }

    public function test_an_explicit_revoke_clears_a_conflict(): void
    {
        $clerk = User::factory()->create();
        $this->asRole($clerk, Role::MANAGER);

        $this->grant($clerk, ['payables.approve', 'payables.execute']);
        $this->grant($clerk, ['payables.execute'], granted: false);

        $findings = SegregationOfDuties::findings($this->company);

        $this->assertFalse($findings->contains(
            fn (array $f) => $f['user']->is($clerk) && $f['rule']['key'] === 'payables.approve-and-execute'
        ));
    }

    public function test_a_manager_cannot_open_the_governance_screen(): void
    {
        $manager = User::factory()->create();
        $this->asRole($manager, Role::MANAGER);
        $this->actingAs($manager);

        Livewire::test(Governance::class)->assertForbidden();
    }

    public function test_the_governance_screen_shows_the_conflict(): void
    {
        $clerk = User::factory()->create(['name' => 'Conflicted Clerk']);
        $this->asRole($clerk, Role::MANAGER);
        $this->grant($clerk, ['payables.approve', 'payables.execute']);

        Livewire::test(Governance::class)
            ->assertSee('Conflicted Clerk')
            ->assertSee('Approves a payment run and releases it');
    }

    protected function grant(User $user, array $abilities, bool $granted = true): void
    {
        foreach ($abilities as $ability) {
            CompanyUserPermission::updateOrCreate([
                'company_id' => $this->company->id,
                'user_id' => $user->id,
                'permission_id' => Permission::where('slug', $ability)->value('id'),
            ], ['granted' => $granted]);
        }

        $user->forgetRoleCache();
    }
}
