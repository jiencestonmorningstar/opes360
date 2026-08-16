<?php

namespace Tests\Feature;

use App\Events\DomainEvent;
use App\Listeners\PostApprovedExpenseClaims;
use App\Models\Company;
use App\Models\CostCentre;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Accounting\Ledger;
use App\Services\ExpenseClaimService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Money a member of staff spent out of their own pocket, and getting it back.
 *
 * Distinct from an Expense, which is money the business itself paid. Between
 * the claim being approved and the cash leaving, the business owes the
 * employee — that debt is the whole reason this is not just an Expense with a
 * different label.
 */
class ExpenseClaimsTest extends TestCase
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

        ChartOfAccounts::seed($this->company);
    }

    // ---------------------------------------------------------------- helpers

    protected function service(): ExpenseClaimService
    {
        return app(ExpenseClaimService::class);
    }

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    protected function employee(): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Awa',
            'last_name' => 'Ngo',
            'status' => 'active',
            'hired_on' => now()->subYear()->toDateString(),
        ]);
    }

    protected function costCentre(?Company $company = null): CostCentre
    {
        return CostCentre::create([
            'company_id' => ($company ?? $this->company)->id,
            'code' => 'CC-'.Str::upper(Str::random(4)),
            'name' => 'Field operations',
            'is_active' => true,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function claim(?array $lines = null, array $attributes = []): ExpenseClaim
    {
        return $this->service()->create(array_merge([
            'employee_id' => $this->employee()->id,
            'title' => 'Site visit, Douala',
            'claim_date' => now()->toDateString(),
            'lines' => $lines ?? [
                ['description' => 'Taxi', 'category' => 'transport', 'incurred_on' => now()->toDateString(), 'amount' => 12_000],
                ['description' => 'Airtime', 'category' => 'telecoms', 'incurred_on' => now()->toDateString(), 'amount' => 3_000],
            ],
        ], $attributes), $this->owner);
    }

    protected function approvalWorkflow(): Workflow
    {
        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Expense claim approval',
            'subject_type' => ExpenseClaim::class,
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

    protected function manager(): User
    {
        $user = User::factory()->create();
        $this->joinCompany($this->company, $user, Role::MANAGER);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    /** Take a claim all the way through a real approval and return it fresh. */
    protected function approved(?ExpenseClaim $claim = null): ExpenseClaim
    {
        $claim ??= $this->claim();
        $manager = $this->manager();

        $instance = $this->service()->submit($claim, $this->approvalWorkflow(), $this->owner);
        $this->engine()->act($instance, $manager, 'approved');

        return $claim->fresh();
    }

    // ------------------------------------------------------------ the claim

    public function test_a_claim_totals_its_lines(): void
    {
        $claim = $this->claim();

        $this->assertSame('15000.00', $claim->subtotal);
        $this->assertSame('15000.00', $claim->total);
        $this->assertSame('draft', $claim->status);
        $this->assertCount(2, $claim->lines);
    }

    public function test_vat_on_a_line_is_reclaimed_separately_from_the_net(): void
    {
        $claim = $this->claim([
            ['description' => 'Hotel', 'category' => 'other', 'incurred_on' => now()->toDateString(), 'amount' => 100_000, 'vat_rate' => 0.1925],
        ]);

        $this->assertSame('100000.00', $claim->subtotal);
        $this->assertSame('19250.00', $claim->vat_amount);
        $this->assertSame('119250.00', $claim->total);
    }

    public function test_a_line_can_be_allocated_to_a_cost_centre(): void
    {
        $centre = $this->costCentre();

        $claim = $this->claim([[
            'description' => 'Fuel', 'category' => 'fuel', 'incurred_on' => now()->toDateString(),
            'amount' => 20_000, 'cost_centre_id' => $centre->id,
        ]]);

        $this->assertSame($centre->id, $claim->lines->first()->cost_centre_id);
    }

    public function test_a_cost_centre_from_another_company_is_refused(): void
    {
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => User::factory()->create()->id,
            'currency' => 'XAF',
            'plan' => 'basic',
            'account_type' => 'active',
        ]);

        $this->expectException(RuntimeException::class);

        $this->claim([[
            'description' => 'Fuel', 'category' => 'fuel', 'incurred_on' => now()->toDateString(),
            'amount' => 20_000, 'cost_centre_id' => $this->costCentre($other)->id,
        ]]);
    }

    public function test_a_claim_with_no_lines_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->claim([]);
    }

    // ---------------------------------------------------------- the approval

    public function test_submitting_runs_the_shared_workflow_engine(): void
    {
        $manager = $this->manager();
        $claim = $this->claim();

        $instance = $this->service()->submit($claim, $this->approvalWorkflow(), $this->owner);

        $this->assertSame('running', $instance->status);
        $this->assertSame([$manager->id], $instance->assignments->pluck('user_id')->all());
        $this->assertTrue($claim->fresh()->isAwaitingApproval());
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_a_submitted_claim_cannot_be_submitted_again(): void
    {
        $this->manager();
        $claim = $this->claim();
        $workflow = $this->approvalWorkflow();

        $this->service()->submit($claim, $workflow, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->service()->submit($claim->fresh(), $workflow, $this->owner);
    }

    public function test_an_approved_claim_reports_itself_approved(): void
    {
        $this->assertTrue($this->approved()->isApproved());
    }

    public function test_a_rejected_claim_is_not_reimbursable(): void
    {
        $manager = $this->manager();
        $claim = $this->claim();

        $instance = $this->service()->submit($claim, $this->approvalWorkflow(), $this->owner);
        $this->engine()->act($instance, $manager, 'rejected');

        $this->assertFalse($claim->fresh()->isApproved());

        $this->expectException(RuntimeException::class);
        $this->service()->reimburse($claim->fresh(), ['amount' => 15_000, 'method' => 'cash'], $this->owner);
    }

    public function test_an_unapproved_claim_cannot_be_reimbursed(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->reimburse($this->claim(), ['amount' => 15_000, 'method' => 'cash'], $this->owner);
    }

    // ------------------------------------------------------------- the books

    public function test_approval_charges_the_expense_accounts_and_owes_the_employee(): void
    {
        $claim = $this->approved();
        $this->service()->postApproval($claim, $this->owner);

        $entry = app(Ledger::class)->entryFor($this->company, $claim->fresh());

        $this->assertNotNull($entry);
        $this->assertSame('AC', $entry->journal);

        $byAccount = $entry->load('lines.account')->lines->mapWithKeys(fn ($line) => [
            $line->account->number => [(float) $line->debit, (float) $line->credit],
        ]);

        // 614 transport, 628 telecoms, 422 owed to the member of staff.
        $this->assertSame([12000.0, 0.0], $byAccount['614']);
        $this->assertSame([3000.0, 0.0], $byAccount['628']);
        $this->assertSame([0.0, 15000.0], $byAccount['422']);
    }

    public function test_posting_the_same_approval_twice_does_not_double_the_books(): void
    {
        $claim = $this->approved();

        $this->service()->postApproval($claim, $this->owner);
        $this->service()->postApproval($claim->fresh(), $this->owner);

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', $claim->getMorphClass())
            ->where('source_id', $claim->id)
            ->count());
    }

    public function test_the_workflow_approval_event_posts_the_claim(): void
    {
        Event::listen(DomainEvent::class, PostApprovedExpenseClaims::class);

        $claim = $this->approved();

        $this->assertSame('approved', $claim->status);
        $this->assertNotNull(app(Ledger::class)->entryFor($this->company, $claim));
    }

    public function test_reimbursing_clears_what_is_owed_and_takes_it_out_of_the_till(): void
    {
        $claim = $this->approved();
        $this->service()->postApproval($claim, $this->owner);

        $reimbursement = $this->service()->reimburse(
            $claim->fresh(),
            ['amount' => 15_000, 'method' => 'cash', 'paid_on' => now()->toDateString()],
            $this->owner,
        );

        $entry = app(Ledger::class)->entryFor($this->company, $reimbursement);

        $this->assertSame('CA', $entry->journal);

        $byAccount = $entry->load('lines.account')->lines->mapWithKeys(fn ($line) => [
            $line->account->number => [(float) $line->debit, (float) $line->credit],
        ]);

        $this->assertSame([15000.0, 0.0], $byAccount['422']);
        $this->assertSame([0.0, 15000.0], $byAccount['571']);

        $claim = $claim->fresh();
        $this->assertSame('reimbursed', $claim->status);
        $this->assertTrue($claim->isSettled());
    }

    public function test_a_bank_reimbursement_lands_in_the_bank_journal(): void
    {
        $claim = $this->approved();

        $reimbursement = $this->service()->reimburse(
            $claim,
            ['amount' => 15_000, 'method' => 'bank'],
            $this->owner,
        );

        $this->assertSame('BQ', app(Ledger::class)
            ->entryFor($this->company, $reimbursement)->journal);
    }

    public function test_a_partial_reimbursement_leaves_a_balance(): void
    {
        $claim = $this->approved();

        $this->service()->reimburse($claim, ['amount' => 5_000, 'method' => 'cash'], $this->owner);

        $claim = $claim->fresh();

        $this->assertSame('approved', $claim->status);
        $this->assertSame(10000.0, $claim->balance());
        $this->assertFalse($claim->isSettled());
    }

    public function test_paying_more_than_is_owed_is_refused(): void
    {
        $claim = $this->approved();

        $this->expectException(RuntimeException::class);
        $this->service()->reimburse($claim, ['amount' => 15_001, 'method' => 'cash'], $this->owner);
    }

    public function test_a_reimbursement_of_nothing_is_refused(): void
    {
        $claim = $this->approved();

        $this->expectException(RuntimeException::class);
        $this->service()->reimburse($claim, ['amount' => 0, 'method' => 'cash'], $this->owner);
    }

    // ------------------------------------------------------------- tenancy

    public function test_claims_are_scoped_to_the_current_company(): void
    {
        $this->claim();

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => User::factory()->create()->id,
            'currency' => 'XAF',
            'plan' => 'basic',
            'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, ExpenseClaim::query()->count());
    }
}
