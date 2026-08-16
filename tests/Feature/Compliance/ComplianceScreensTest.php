<?php

namespace Tests\Feature\Compliance;

use App\Livewire\Compliance\Index;
use App\Models\ComplianceObligation;
use App\Models\Role;
use Livewire\Livewire;

/**
 * The calendar screen. What it must get right is narrow and non-negotiable:
 * a missed deadline is visible, filing moves the next one, and a refusal from
 * the service reaches the person who caused it in words they can act on.
 */
class ComplianceScreensTest extends ComplianceTestCase
{
    public function test_an_overdue_obligation_is_counted_and_listed(): void
    {
        $this->obligation([
            'name' => 'TVA declaration',
            'next_due_on' => now()->subDays(10)->toDateString(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->assertSee('TVA declaration')
            ->assertViewHas('summary', fn (array $summary) => $summary['overdue'] === 1);
    }

    public function test_filing_moves_the_deadline_on(): void
    {
        $obligation = $this->obligation([
            'interval_months' => 3,
            'schedule_basis' => 'due',
            'next_due_on' => now()->subDays(5)->toDateString(),
        ]);

        $due = $obligation->next_due_on->copy();

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('startFiling', $obligation->id)
            ->set('completedOn', now()->toDateString())
            ->set('reference', 'DGI-2026-0042')
            ->call('file')
            ->assertHasNoErrors();

        // Counted from the deadline, not from today: being late does not buy
        // the business another quarter.
        $this->assertSame(
            $due->copy()->addMonths(3)->toDateString(),
            $obligation->fresh()->next_due_on->toDateString(),
        );
    }

    public function test_a_refused_filing_shows_the_reason_it_was_refused(): void
    {
        // Sign-off demanded and no workflow defined. The service refuses rather
        // than waving it through, and the screen has to say so.
        $obligation = $this->obligation(['requires_approval' => true]);

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('startFiling', $obligation->id)
            ->call('file')
            ->assertHasErrors('filing');

        $this->assertSame('draft', $obligation->fresh()->openFiling()->status);
    }

    public function test_filing_needs_the_file_ability(): void
    {
        $obligation = $this->obligation();

        // A manager keeps the calendar but cannot swear a return went in.
        $manager = $this->memberAt(Role::MANAGER);

        Livewire::actingAs($manager)
            ->test(Index::class)
            ->call('startFiling', $obligation->id)
            ->assertForbidden();
    }

    public function test_an_obligation_is_added_with_an_explicit_schedule_basis(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->call('startAdding')
            ->set('name', 'Trading licence renewal')
            ->set('category', 'licence')
            ->set('intervalMonths', '12')
            ->set('scheduleBasis', 'completion')
            ->set('nextDueOn', now()->addMonths(2)->toDateString())
            ->call('addObligation')
            ->assertHasNoErrors();

        $this->assertSame(
            'completion',
            ComplianceObligation::where('name', 'Trading licence renewal')->first()->schedule_basis,
        );
    }
}
