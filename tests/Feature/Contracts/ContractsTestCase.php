<?php

namespace Tests\Feature\Contracts;

use App\Events\DomainEvent;
use App\Listeners\ActivateApprovedContracts;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Contracts\ContractLifecycle;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One company, one counterparty, one owner — everything a contract needs to
 * exist, and nothing else.
 */
abstract class ContractsTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        /*
         * Registered here because AppServiceProvider is the orchestrator's to
         * edit, not this feature's. Once the line lands there this becomes a
         * duplicate registration, which is harmless — activate() is idempotent
         * and returns the contract untouched if it is already running.
         */
        Event::listen(DomainEvent::class, ActivateApprovedContracts::class);

        $this->supplier = Contact::create([
            'company_id' => $this->company->id,
            'type' => 'supplier',
            'name' => 'Sonel Facilities',
        ]);
    }

    protected function service(): ContractLifecycle
    {
        return app(ContractLifecycle::class);
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

    protected function workflow(): Workflow
    {
        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Contract approval',
            'subject_type' => Contract::class,
            'is_active' => true,
            'is_default' => true,
        ]);

        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Manager',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
        ]);

        return $workflow->fresh();
    }

    /** @param  array<string, mixed>  $overrides */
    protected function contract(array $overrides = []): Contract
    {
        return $this->service()->raise(array_merge([
            'title' => 'Office cleaning',
            'contact_id' => $this->supplier->id,
            'direction' => 'inbound',
            'type' => 'service',
            'value' => 1_200_000,
            'currency' => 'XAF',
            'starts_on' => now()->subMonths(6)->toDateString(),
            'ends_on' => now()->addMonths(6)->toDateString(),
            'renewal_type' => 'auto',
            'renewal_term_months' => 12,
            'notice_period_days' => 60,
            'owner_id' => $this->owner->id,
        ], $overrides), $this->owner);
    }

    /** A contract that has been agreed and is running. */
    protected function activeContract(array $overrides = []): Contract
    {
        $contract = $this->contract($overrides);

        $this->service()->activate($contract, $this->owner);

        return $contract->fresh();
    }

    /** @param  Collection<int, Contract>  $rows */
    protected function assertContractIn(Contract $contract, $rows): void
    {
        $this->assertTrue(
            $rows->pluck('id')->contains($contract->id),
            "Expected {$contract->title} on the list, and it was not there."
        );
    }

    /** @param  Collection<int, Contract>  $rows */
    protected function assertContractNotIn(Contract $contract, $rows): void
    {
        $this->assertFalse(
            $rows->pluck('id')->contains($contract->id),
            "Did not expect {$contract->title} on the list, and it was there."
        );
    }
}
