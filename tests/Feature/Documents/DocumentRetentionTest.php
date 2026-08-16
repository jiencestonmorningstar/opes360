<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentRetentionPolicy;
use App\Models\Role;
use App\Services\Documents\DocumentRetention;
use RuntimeException;

class DocumentRetentionTest extends DocumentsTestCase
{
    public function test_a_document_with_no_policy_has_no_retention_date(): void
    {
        $this->assertNull($this->retention()->retainUntil($this->document()));
    }

    public function test_a_kind_specific_policy_applies_over_the_catch_all(): void
    {
        $this->policy(null, 5);
        $this->policy('contract', 10);

        $contract = $this->document(['kind' => 'contract']);
        $memo = $this->document(['kind' => 'memo']);

        $contractRetainUntil = $this->retention()->retainUntil($contract);
        $memoRetainUntil = $this->retention()->retainUntil($memo);

        $this->assertSame($contract->created_at->addYears(10)->toDateString(), $contractRetainUntil->toDateString());
        $this->assertSame($memo->created_at->addYears(5)->toDateString(), $memoRetainUntil->toDateString());
    }

    public function test_retention_counts_from_issuance_not_creation_once_issued(): void
    {
        $this->policy('contract', 7);
        $paper = $this->document(['kind' => 'contract']);

        $this->travel(30)->days();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $retainUntil = $this->retention()->retainUntil($paper->fresh());

        $this->assertSame($paper->fresh()->issued_at->addYears(7)->toDateString(), $retainUntil->toDateString());
    }

    public function test_a_document_is_not_disposable_before_its_retention_date(): void
    {
        $this->policy(null, 5);

        $this->assertFalse($this->retention()->isDisposable($this->document()));
    }

    public function test_a_document_is_disposable_once_retention_has_passed(): void
    {
        $this->policy(null, 1);
        $paper = $this->document();

        $this->travel(2)->years();

        $this->assertTrue($this->retention()->isDisposable($paper->fresh()));
    }

    // ── Legal hold ───────────────────────────────────────────────────────

    public function test_a_legal_hold_can_be_placed_with_a_reason(): void
    {
        $paper = $this->document();

        $held = $this->retention()->placeLegalHold($paper, 'Pending litigation.', $this->owner);

        $this->assertTrue($held->isUnderLegalHold());
        $this->assertSame('Pending litigation.', $held->legal_hold_reason);
        $this->assertSame($this->owner->id, $held->legal_hold_set_by);
    }

    public function test_a_legal_hold_blocks_disposal_regardless_of_retention(): void
    {
        $this->policy(null, 1);
        $paper = $this->document();
        $this->travel(2)->years();
        $this->retention()->placeLegalHold($paper->fresh(), 'Pending litigation.', $this->owner);

        $this->assertFalse($this->retention()->isDisposable($paper->fresh()));
    }

    public function test_disposal_refuses_a_document_under_legal_hold(): void
    {
        $this->policy(null, 1);
        $paper = $this->document();
        $this->travel(2)->years();
        $this->retention()->placeLegalHold($paper->fresh(), 'Pending litigation.', $this->owner);

        $this->expectException(RuntimeException::class);

        $this->retention()->dispose($paper->fresh());
    }

    public function test_lifting_a_hold_allows_disposal_again(): void
    {
        $this->policy(null, 1);
        $paper = $this->document();
        $this->travel(2)->years();
        $this->retention()->placeLegalHold($paper->fresh(), 'Reason.', $this->owner);

        $this->retention()->liftLegalHold($paper->fresh());

        $this->assertTrue($this->retention()->isDisposable($paper->fresh()));
    }

    /** A hold can be placed on an issued document — that is the common case. */
    public function test_a_legal_hold_can_be_placed_on_an_issued_document_without_breaking_its_hash(): void
    {
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $this->retention()->placeLegalHold($paper->fresh(), 'Reason.', $this->owner);

        $this->assertFalse($paper->fresh()->isTampered());
    }

    // ── Disposal ─────────────────────────────────────────────────────────

    public function test_disposal_refuses_a_document_before_its_retention_date(): void
    {
        $this->policy(null, 5);

        $this->expectException(RuntimeException::class);

        $this->retention()->dispose($this->document());
    }

    public function test_disposal_permanently_removes_the_document(): void
    {
        $this->policy(null, 1);
        $paper = $this->document();
        $this->travel(2)->years();

        $this->retention()->dispose($paper->fresh());

        $this->assertDatabaseCount('business_documents', 0);
    }

    // ── Lifecycle labels ─────────────────────────────────────────────────

    public function test_lifecycle_label_for_a_plain_draft(): void
    {
        $this->assertSame('Draft', $this->document()->lifecycleLabel());
    }

    public function test_lifecycle_label_for_a_locked_draft(): void
    {
        $this->assertSame('Locked', $this->document(['is_locked' => true])->lifecycleLabel());
    }

    public function test_lifecycle_label_for_an_issued_document(): void
    {
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $this->assertSame('Issued', $paper->fresh()->lifecycleLabel());
    }

    public function test_lifecycle_label_for_an_expired_issued_document(): void
    {
        $paper = $this->document(['expires_on' => now()->addDay()->toDateString()]);
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $this->travel(3)->days();

        $this->assertSame('Expired', $paper->fresh()->lifecycleLabel());
    }

    public function test_lifecycle_label_for_a_legal_hold_overrides_everything_else(): void
    {
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();
        $this->retention()->placeLegalHold($paper->fresh(), 'Reason.', $this->owner);

        $this->assertSame('On legal hold', $paper->fresh()->lifecycleLabel());
    }

    // ── Permissions ───────────────────────────────────────────────────────

    public function test_only_a_document_administrator_may_place_a_legal_hold(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $admin = $this->memberAt(Role::ADMINISTRATOR);
        $paper = $this->document();

        $this->assertFalse($manager->can('manage', $paper));
        $this->assertTrue($admin->can('manage', $paper));
    }

    protected function policy(?string $kind, int $years): BusinessDocumentRetentionPolicy
    {
        return BusinessDocumentRetentionPolicy::create([
            'kind' => $kind,
            'retain_years' => $years,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function retention(): DocumentRetention
    {
        return app(DocumentRetention::class);
    }
}
