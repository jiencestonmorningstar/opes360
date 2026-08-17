<?php

namespace Tests\Feature\Compliance;

use App\Livewire\Compliance\Index;
use App\Models\Role;
use App\Services\Compliance\ComplianceRegister;
use App\Services\Workflow\WorkflowEngine;
use Livewire\Livewire;
use RuntimeException;

/**
 * A refused statutory filing is not a dead end.
 *
 * The deadline the refusal was about is still there, so "refused" must be
 * recoverable: back to being prepared, corrected, and filed again.
 */
class ComplianceRefileTest extends ComplianceTestCase
{
    protected function refusedFiling()
    {
        $this->signOffWorkflow();
        $manager = $this->memberAt(Role::MANAGER);

        $obligation = $this->obligation(['requires_approval' => true]);
        $register = app(ComplianceRegister::class);

        $filing = $register->raise($obligation);
        $register->submit($filing, $this->owner);

        $instance = $filing->fresh()->approval();
        app(WorkflowEngine::class)->act($instance, $manager, 'rejected');

        return $filing->fresh();
    }

    public function test_a_refused_filing_can_be_sent_back_and_filed_again(): void
    {
        $filing = $this->refusedFiling();
        $this->assertSame('rejected', $filing->status);

        $this->actingAs($this->owner);

        Livewire::test(Index::class)
            ->assertSee('Refused')
            ->call('reprepare', $filing->id)
            ->assertHasNoErrors();

        $filing->refresh();
        $this->assertSame('draft', $filing->status);

        // And it really can go round again.
        $instance = app(ComplianceRegister::class)->submit($filing, $this->owner)->approval();
        $this->assertNotNull($instance);
    }

    public function test_a_completed_filing_cannot_be_sent_back(): void
    {
        $obligation = $this->obligation();
        $register = app(ComplianceRegister::class);

        $filing = $register->raise($obligation);
        $register->submit($filing, $this->owner);
        $this->assertSame('completed', $filing->fresh()->status);

        $this->expectException(RuntimeException::class);

        $register->returnToPreparer($filing->fresh());
    }
}
