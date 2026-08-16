<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseRequisition;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Support\CurrentCompany;
use App\Support\DefaultWorkflows;
use App\Support\WorkflowConditions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A new business can actually get something approved.
 *
 * The approval engine is data-driven and knows nothing about requisitions or
 * claims — which is right, and had one consequence nobody noticed until the
 * screens went in: a business with no workflow rows cannot submit anything at
 * all. Every `submit` refused with "no approval path is defined", correctly
 * and uselessly, because there is no screen on which to define one.
 */
class DefaultWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);
    }

    /** Every subject that can be submitted has somewhere to submit it to. */
    public function test_a_new_business_gets_a_path_for_everything_it_can_submit(): void
    {
        DefaultWorkflows::seed($this->company);

        foreach (DefaultWorkflows::subjects() as $subject) {
            $this->assertNotNull(
                Workflow::defaultFor($subject),
                "Nothing could ever be submitted for {$subject}."
            );
        }
    }

    /**
     * The approver is resolved when the step is reached, not when it is
     * written down. A seeded workflow naming a person would be wrong the day
     * the business changed hands.
     */
    public function test_the_seeded_step_asks_whoever_owns_the_business_now(): void
    {
        DefaultWorkflows::seed($this->company);

        $step = Workflow::defaultFor(PurchaseRequisition::class)->steps()->first();

        $this->assertSame('owner', $step->approver_mode);
        $this->assertNull($step->approver_user_id, 'A named person goes stale.');
    }

    /**
     * A business that has replaced the seeded path with its own must never
     * have it quietly reinstated underneath them.
     */
    public function test_seeding_again_leaves_what_the_business_has_changed(): void
    {
        DefaultWorkflows::seed($this->company);

        $workflow = Workflow::defaultFor(PurchaseRequisition::class);
        $workflow->update(['name' => 'Our own way of doing it']);
        $workflow->steps()->delete();

        DefaultWorkflows::seed($this->company);

        $this->assertSame(
            1,
            Workflow::where('subject_type', PurchaseRequisition::class)->count(),
            'A second path appeared beside the one the business wrote.'
        );
        $this->assertSame('Our own way of doing it', $workflow->fresh()->name);
    }

    /**
     * A box of pens does not need the owner.
     *
     * The step carries an amount condition, and a step whose condition fails
     * is skipped — so a small requisition passes straight through with the
     * submission still on the record. Without this the owner approves every
     * trivial purchase until they switch the workflow off, and then nothing
     * is approved by anybody.
     */
    public function test_a_small_requisition_goes_through_without_asking_anyone(): void
    {
        DefaultWorkflows::seed($this->company);

        $step = Workflow::defaultFor(PurchaseRequisition::class)->steps()->first();
        $threshold = DefaultWorkflows::thresholdFor($this->company);

        $this->assertSame('estimated_total', $step->conditions[0]['field']);
        $this->assertSame('>', $step->conditions[0]['operator']);
        $this->assertSame($threshold, $step->conditions[0]['value']);

        $conditions = app(WorkflowConditions::class);
        $small = new PurchaseRequisition(['estimated_total' => $threshold - 1]);
        $large = new PurchaseRequisition(['estimated_total' => $threshold + 1]);

        $this->assertFalse($conditions->passes($step, $small), 'A small one should not stop.');
        $this->assertTrue($conditions->passes($step, $large), 'A large one must be looked at.');
    }

    /**
     * 500 000 XAF is a van; 500 000 USD is a building. One figure cannot
     * serve both, so the seeded threshold is read from what the business
     * actually trades in.
     */
    public function test_the_threshold_is_in_the_businesss_own_money(): void
    {
        $this->assertGreaterThan(
            DefaultWorkflows::thresholdFor(new Company(['currency' => 'USD'])),
            DefaultWorkflows::thresholdFor(new Company(['currency' => 'XAF'])),
        );

        $this->assertGreaterThan(
            0,
            DefaultWorkflows::thresholdFor(new Company(['currency' => 'ZZZ'])),
            'A currency we have never seen must still get asked about something.'
        );
    }

    /**
     * The businesses that already exist are exactly the ones with work
     * waiting, and seeding on create does nothing for them.
     */
    public function test_the_backfill_command_gives_an_existing_business_its_paths(): void
    {
        $this->assertSame(0, Workflow::count(), 'This business predates the seeding.');

        $this->artisan('opes:seed-workflows')->assertSuccessful();

        foreach (DefaultWorkflows::subjects() as $subject) {
            $this->assertNotNull(Workflow::defaultFor($subject), "Still nothing for {$subject}.");
        }
    }

    /** Signing up gives you the paths, without anybody having to know to ask. */
    public function test_registering_a_business_provisions_its_approval_paths(): void
    {
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        DefaultWorkflows::seed($other);

        $this->assertSame(
            count(DefaultWorkflows::subjects()),
            Workflow::withoutGlobalScopes()->where('company_id', $other->id)->count()
        );
        $this->assertSame(
            0,
            Workflow::withoutGlobalScopes()
                ->where('company_id', $other->id)
                ->where('is_default', false)
                ->count(),
            'Every seeded path must be the default for its subject, or nothing finds it.'
        );
    }
}
