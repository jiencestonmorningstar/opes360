<?php

namespace Tests\Feature\Insurance;

use App\Events\DomainEvent;
use App\Listeners\SettleApprovedInsuranceClaims;
use App\Livewire\Insurance\Index;
use App\Livewire\Insurance\Show;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Insurance\Claims;
use App\Services\Insurance\Policies;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One brokerage, one client, one insurer — everything a policy needs to
 * exist, and nothing else.
 */
abstract class InsuranceTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $client;

    protected Contact $insurer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'broker-'.Str::lower(Str::random(6)),
            'name' => 'Assurance Plus Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        // Insurance ships off; off means every gate denies, so the module
        // under test is switched on for the business under test.
        $this->company->forceFill(['modules' => ['insurance' => true]])->save();
        Modules::flush();

        app(CurrentCompany::class)->set($this->company);

        // The insurance gates are registered by the integrator alongside the
        // routes (see docs/handoff/insurance.md). Defined here so these tests
        // exercise the screens and services, not the wiring — the same
        // bargain tests/Feature/Manufacturing/ProductionTest.php makes.
        foreach (['insurance.view', 'insurance.manage', 'insurance.settle'] as $ability) {
            Gate::define($ability, fn (User $user) => true);
        }

        /*
         * Registered here because AppServiceProvider is the orchestrator's to
         * edit, not this vertical's. Once the line lands there this becomes a
         * duplicate registration, which is harmless — settle() returns a
         * settled claim untouched.
         */
        Event::listen(DomainEvent::class, SettleApprovedInsuranceClaims::class);

        /*
         * The routes are the integrator's to add to routes/web.php; named
         * here so the blades' route() calls resolve while the screens are
         * under test. These are the names the handoff document expects.
         */
        Route::get('/insurance', Index::class)
            ->middleware(['web'])->name('insurance');
        Route::get('/insurance/{policy}', Show::class)
            ->middleware(['web'])->name('insurance.show');

        $this->client = Contact::create([
            'company_id' => $this->company->id,
            'type' => 'customer',
            'name' => 'Transport Nkolbisson',
        ]);

        $this->insurer = Contact::create([
            'company_id' => $this->company->id,
            'type' => 'supplier',
            'name' => 'Activa Assurances',
        ]);
    }

    protected function policies(): Policies
    {
        return app(Policies::class);
    }

    protected function claims(): Claims
    {
        return app(Claims::class);
    }

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    /** A member holding a role, so a workflow step has somebody to assign to. */
    protected function memberAt(string $role): User
    {
        $user = User::factory()->create();

        $this->joinCompany($this->company, $user, $role);

        return $user;
    }

    /** The one-step settlement workflow a brokerage would actually define. */
    protected function settlementWorkflow(): Workflow
    {
        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Claim settlement approval',
            'subject_type' => InsuranceClaim::class,
            'is_active' => true,
            'is_default' => true,
        ]);

        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Principal',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
        ]);

        return $workflow->fresh();
    }

    /** @param  array<string, mixed>  $overrides */
    protected function policy(array $overrides = []): InsurancePolicy
    {
        return $this->policies()->place(array_merge([
            'policy_number' => 'ACT-2026-00417',
            'holder_contact_id' => $this->client->id,
            'insurer_contact_id' => $this->insurer->id,
            'product_line' => 'motor',
            'premium' => 850_000,
            'commission_percent' => 12.5,
            'covers_from' => now()->subMonths(11)->toDateString(),
            'covers_to' => now()->addMonth()->toDateString(),
            'renewal_type' => 'manual',
        ], $overrides), $this->owner);
    }

    /** Cover that has been bound and is in force. */
    protected function activePolicy(array $overrides = []): InsurancePolicy
    {
        $policy = $this->policy($overrides);

        $this->policies()->bind($policy, $this->owner);

        return $policy->fresh();
    }

    /** @param  Collection<int, InsurancePolicy>  $rows */
    protected function assertPolicyIn(InsurancePolicy $policy, $rows): void
    {
        $this->assertTrue(
            $rows->pluck('id')->contains($policy->id),
            "Expected {$policy->label()} on the list, and it was not there."
        );
    }

    /** @param  Collection<int, InsurancePolicy>  $rows */
    protected function assertPolicyNotIn(InsurancePolicy $policy, $rows): void
    {
        $this->assertFalse(
            $rows->pluck('id')->contains($policy->id),
            "Did not expect {$policy->label()} on the list, and it was there."
        );
    }
}
