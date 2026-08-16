<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Confidentiality is the policy's job, not a screen's.
 *
 * A workspace that merely leaves a restricted document out of a list still
 * hands it over to anyone who knows its URL, its API route, or its id — so
 * every test here asks the policy directly and none of them go near a view.
 *
 * All of them drive a non-Owner. The Owner short-circuits every permission
 * check by design, which is exactly why an Owner-driven test proves nothing.
 */
class DocumentPermissionTest extends DocumentsTestCase
{
    public function test_the_papers_group_carries_share_and_manage(): void
    {
        $this->assertSame(
            ['view', 'create', 'issue', 'void', 'share', 'manage'],
            Permissions::CATALOGUE['Papers'],
        );
    }

    /** An ability with no gate is silently denied to everybody but the Owner. */
    public function test_both_new_abilities_have_gates(): void
    {
        $this->assertTrue(Gate::has('papers.share'));
        $this->assertTrue(Gate::has('papers.manage'));
    }

    public function test_a_manager_may_share_but_may_not_manage(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $paper = $this->document();

        $this->assertTrue($manager->can('share', $paper));
        $this->assertFalse($manager->can('manage', $paper));
    }

    public function test_an_administrator_may_manage(): void
    {
        $this->assertTrue($this->memberAt(Role::ADMINISTRATOR)->can('manage', $this->document()));
    }

    public function test_a_read_only_user_may_neither_share_nor_manage(): void
    {
        $auditor = $this->memberAt(Role::READ_ONLY);
        $paper = $this->document();

        $this->assertTrue($auditor->can('view', $paper));
        $this->assertFalse($auditor->can('share', $paper));
        $this->assertFalse($auditor->can('manage', $paper));
    }

    public function test_a_restricted_document_is_refused_to_a_colleague_who_may_read_papers(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        $restricted = $this->document(['security' => 'restricted']);

        $this->assertFalse($clerk->can('view', $restricted));
        $this->assertFalse($clerk->can('update', $restricted));
        $this->assertFalse($clerk->can('delete', $restricted));
        $this->assertFalse($clerk->can('share', $restricted));
    }

    /** The refusal must be about the marking, not a blanket denial. */
    public function test_the_same_colleague_reads_an_ordinary_document(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);

        $this->assertTrue($clerk->can('view', $this->document()));
    }

    /**
     * Confidential is a marking; restricted is a rule. A document nobody but
     * its owner can open is no use to a business that has to work on it, so
     * only the top level narrows the audience.
     */
    /**
     * Filing is not editing. update() rightly refuses any change to an issued
     * document — but file() must still allow moving one into a folder, or the
     * one case filing exists for (an already-signed contract) is the one case
     * it could never be used on.
     */
    public function test_an_issued_document_can_still_be_filed(): void
    {
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $this->assertFalse($this->owner->can('update', $paper));
        $this->assertTrue($this->owner->can('file', $paper));
    }

    public function test_a_confidential_document_is_marked_but_not_walled_off(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        $confidential = $this->document(['security' => 'confidential']);

        $this->assertTrue($confidential->isConfidential());
        $this->assertTrue($clerk->can('view', $confidential));
    }

    public function test_a_restricted_document_stays_open_to_the_person_who_owns_it(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        $restricted = $this->document(['security' => 'restricted', 'owner_id' => $clerk->id]);

        $this->assertTrue($clerk->can('view', $restricted));
    }

    public function test_a_restricted_document_is_open_to_a_holder_of_papers_manage(): void
    {
        $administrator = $this->memberAt(Role::ADMINISTRATOR);

        $this->assertTrue($administrator->can('view', $this->document(['security' => 'restricted'])));
    }

    /** Owning a restricted document is not a way in without papers.view. */
    public function test_owning_a_restricted_document_does_not_replace_the_view_permission(): void
    {
        $cashier = $this->memberAt(Role::CASHIER);
        $restricted = $this->document(['security' => 'restricted', 'owner_id' => $cashier->id]);

        $this->assertFalse($cashier->can('view', $restricted));
    }

    public function test_the_company_owner_is_never_locked_out_of_a_restricted_document(): void
    {
        $this->assertTrue($this->owner->can('view', $this->document(['security' => 'restricted'])));
    }

    /** papers.manage is authority inside one company, not a passe-partout. */
    public function test_manage_does_not_reach_another_company(): void
    {
        $administrator = $this->memberAt(Role::ADMINISTRATOR);
        $elsewhere = $this->documentInAnotherCompany();

        $this->assertFalse($administrator->can('view', $elsewhere));
        $this->assertFalse($administrator->can('manage', $elsewhere));
    }

    protected function documentInAnotherCompany(): BusinessDocument
    {
        $stranger = User::factory()->create();

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $stranger->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($other, $stranger, Role::OWNER);
        app(CurrentCompany::class)->set($other);

        $paper = $this->document(['created_by' => $stranger->id]);

        app(CurrentCompany::class)->set($this->company);

        return $paper;
    }
}
