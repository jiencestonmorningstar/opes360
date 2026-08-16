<?php

namespace Tests\Feature\Contracts;

use App\Livewire\Contracts\Index;
use App\Livewire\Contracts\Show;
use App\Models\Role;
use Livewire\Livewire;

/**
 * The screens over the contract module.
 *
 * The service is tested next door; what matters here is that the watch screen
 * puts each contract on the right one of its three lists, that an action goes
 * through the service rather than round it, and that when the service refuses
 * the person reading the screen is told why in the words it used.
 */
class ContractScreensTest extends ContractsTestCase
{
    /**
     * The expensive case gets its own list.
     *
     * A missed deadline on a contract that ends by itself and a missed deadline
     * on one that renews itself are the same row and completely different news,
     * and the screen has to be able to tell them apart.
     */
    public function test_the_watch_screen_separates_the_three_kinds_of_deadline(): void
    {
        $this->actingAs($this->owner);

        $atRisk = $this->activeContract([
            'title' => 'Office cleaning',
            'ends_on' => now()->addDays(10)->toDateString(),
            'renewal_type' => 'auto',
            'notice_period_days' => 60,
        ]);

        $gone = $this->activeContract([
            'title' => 'Photocopier lease',
            'ends_on' => now()->addDays(10)->toDateString(),
            'renewal_type' => 'none',
            'notice_period_days' => 60,
        ]);

        $coming = $this->activeContract([
            'title' => 'Security guarding',
            'ends_on' => now()->addDays(40)->toDateString(),
            'renewal_type' => 'none',
            'notice_period_days' => 30,
        ]);

        $screen = Livewire::test(Index::class);

        $this->assertContractIn($atRisk, $screen->viewData('renewingAtRisk'));
        $this->assertContractNotIn($atRisk, $screen->viewData('missed'));

        $this->assertContractIn($gone, $screen->viewData('missed'));
        $this->assertContractNotIn($gone, $screen->viewData('renewingAtRisk'));

        $this->assertContractIn($coming, $screen->viewData('lapsing'));
        $this->assertContractNotIn($coming, $screen->viewData('missed'));
    }

    /** A draft has not been agreed; warning about it teaches people to ignore the list. */
    public function test_the_watch_lists_leave_drafts_alone(): void
    {
        $this->actingAs($this->owner);

        $draft = $this->contract([
            'title' => 'Not agreed yet',
            'ends_on' => now()->addDays(10)->toDateString(),
            'notice_period_days' => 60,
        ]);

        $screen = Livewire::test(Index::class);

        $this->assertContractNotIn($draft, $screen->viewData('renewingAtRisk'));
        $this->assertContractNotIn($draft, $screen->viewData('missed'));
    }

    public function test_the_register_searches_and_filters(): void
    {
        $this->actingAs($this->owner);

        $this->contract(['title' => 'Office cleaning']);
        $this->activeContract(['title' => 'Photocopier lease']);

        Livewire::test(Index::class)
            ->set('search', 'cleaning')
            ->assertSee('Office cleaning')
            ->assertDontSee('Photocopier lease')
            ->set('search', '')
            ->set('status', 'active')
            ->assertSee('Photocopier lease')
            ->assertDontSee('Office cleaning');
    }

    public function test_renewing_from_the_screen_extends_the_contract(): void
    {
        $this->actingAs($this->owner);

        $contract = $this->activeContract(['ends_on' => now()->addMonths(3)->toDateString()]);
        $wasEnding = $contract->ends_on->toDateString();

        Livewire::test(Show::class, ['contract' => $contract])
            ->call('startRenewing')
            ->call('renew')
            ->assertHasNoErrors();

        $contract->refresh();

        $this->assertTrue($contract->ends_on->gt($wasEnding));
        $this->assertSame(1, $contract->renewals()->count());
    }

    public function test_terminating_from_the_screen_ends_it(): void
    {
        $this->actingAs($this->owner);

        $contract = $this->activeContract();

        Livewire::test(Show::class, ['contract' => $contract])
            ->call('startTerminating')
            ->set('terminationReason', 'Poor service')
            ->call('terminate')
            ->assertHasNoErrors();

        $this->assertSame('terminated', $contract->fresh()->status);
    }

    public function test_an_obligation_can_be_added_and_ticked_off(): void
    {
        $this->actingAs($this->owner);

        $contract = $this->activeContract();

        $screen = Livewire::test(Show::class, ['contract' => $contract])
            ->set('owedBy', 'them')
            ->set('obligationTitle', 'Monthly service report')
            ->set('obligationDueOn', now()->addDays(7)->toDateString())
            ->call('addObligation')
            ->assertHasNoErrors();

        $obligation = $contract->obligations()->firstOrFail();
        $this->assertFalse($obligation->isDone());

        $screen->call('completeObligation', $obligation->id);

        $this->assertTrue($obligation->fresh()->isDone());
    }

    /**
     * The service writes its refusals for a business person to read. Swallowing
     * one and showing "something went wrong" would throw away the only part of
     * the message that tells them what to do next.
     */
    public function test_a_refused_renewal_shows_the_reason_the_service_gave(): void
    {
        $this->actingAs($this->owner);

        $draft = $this->contract(['title' => 'Office cleaning']);

        Livewire::test(Show::class, ['contract' => $draft])
            ->call('startRenewing')
            ->call('renew')
            ->assertHasErrors('renewing')
            ->assertSee('Only a contract that is running can be renewed');
    }

    /** The trap the whole module exists to close, refused at the point of entry. */
    public function test_raising_an_auto_renewing_contract_with_no_notice_period_is_refused_out_loud(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Index::class)
            ->call('startRaising')
            ->set('title', 'Silent roll-over')
            ->set('startsOn', now()->toDateString())
            ->set('endsOn', now()->addYear()->toDateString())
            ->set('renewalType', 'auto')
            ->set('noticePeriodDays', '')
            ->call('raise')
            ->assertHasErrors('raising')
            ->assertSee('needs a notice period');
    }

    public function test_submitting_an_already_active_contract_is_refused_out_loud(): void
    {
        $this->actingAs($this->owner);

        $contract = $this->activeContract(['title' => 'Office cleaning']);

        Livewire::test(Show::class, ['contract' => $contract])
            ->call('submit')
            ->assertHasErrors('contract')
            ->assertSee('does not need approving again');
    }

    public function test_somebody_without_the_permission_cannot_open_the_register(): void
    {
        $this->actingAs($this->memberAt(Role::SALES_OFFICER));

        Livewire::test(Index::class)->assertForbidden();
    }

    /**
     * Renewing commits the business to another term, which is why it is a
     * permission of its own rather than part of keeping the register.
     */
    public function test_reading_the_register_does_not_entitle_anybody_to_renew(): void
    {
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $this->actingAs($accountant);

        $contract = $this->activeContract();

        $this->assertTrue($accountant->can('contracts.view'));
        $this->assertFalse($accountant->can('contracts.renew'));

        Livewire::test(Show::class, ['contract' => $contract])
            ->call('renew')
            ->assertForbidden();
    }

    public function test_terminating_is_refused_to_somebody_who_may_only_manage(): void
    {
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $this->actingAs($accountant);

        $contract = $this->activeContract();

        Livewire::test(Show::class, ['contract' => $contract])
            ->call('terminate')
            ->assertForbidden();

        $this->assertSame('active', $contract->fresh()->status);
    }
}
