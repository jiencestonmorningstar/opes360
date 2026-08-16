<?php

namespace Tests\Feature\Documents;

use App\Events\DomainEvent;
use App\Models\Role;
use App\Services\Documents\DocumentSignatureRequests;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class DocumentSignatureTest extends DocumentsTestCase
{
    public function test_requesting_signatures_creates_one_row_per_signer(): void
    {
        $paper = $this->document();

        $signatures = $this->requests()->request($paper, [
            ['name' => 'A Client', 'email' => 'client@example.com'],
            ['name' => 'A Witness', 'email' => 'witness@example.com'],
        ]);

        $this->assertSame(2, $signatures->count());
        $this->assertSame('parallel', $paper->fresh()->signature_mode);
    }

    public function test_a_signature_round_cannot_be_started_with_no_signers(): void
    {
        $this->expectException(RuntimeException::class);

        $this->requests()->request($this->document(), []);
    }

    public function test_a_second_round_cannot_start_while_one_is_pending(): void
    {
        $paper = $this->document();
        $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']]);

        $this->expectException(RuntimeException::class);

        $this->requests()->request($paper->fresh(), [['name' => 'B', 'email' => 'b@example.com']]);
    }

    public function test_signing_marks_the_signature_signed(): void
    {
        $paper = $this->document();
        $signature = $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']])->first();

        $signed = $this->requests()->sign($signature, '203.0.113.7');

        $this->assertTrue($signed->isSigned());
        $this->assertNotNull($signed->signed_at);
        $this->assertSame('203.0.113.7', $signed->ip_address);
    }

    public function test_a_signature_cannot_be_signed_twice(): void
    {
        $paper = $this->document();
        $signature = $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']])->first();
        $this->requests()->sign($signature);

        $this->expectException(RuntimeException::class);

        $this->requests()->sign($signature->fresh());
    }

    public function test_a_signature_can_be_declined_with_a_reason(): void
    {
        $paper = $this->document();
        $signature = $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']])->first();

        $declined = $this->requests()->decline($signature, 'Terms need revising.');

        $this->assertSame('declined', $declined->status);
        $this->assertSame('Terms need revising.', $declined->declined_reason);
    }

    // ── Sequential ordering ──────────────────────────────────────────────

    public function test_a_later_signer_cannot_go_before_an_earlier_one_in_a_sequential_round(): void
    {
        $paper = $this->document();
        $signatures = $this->requests()->request($paper, [
            ['name' => 'First', 'email' => 'first@example.com'],
            ['name' => 'Second', 'email' => 'second@example.com'],
        ], 'sequential');

        $this->expectException(RuntimeException::class);

        $this->requests()->sign($signatures->last());
    }

    public function test_the_second_signer_may_go_once_the_first_has(): void
    {
        $paper = $this->document();
        $signatures = $this->requests()->request($paper, [
            ['name' => 'First', 'email' => 'first@example.com'],
            ['name' => 'Second', 'email' => 'second@example.com'],
        ], 'sequential');

        $this->requests()->sign($signatures->first());

        $second = $this->requests()->sign($signatures->last()->fresh());

        $this->assertTrue($second->isSigned());
    }

    public function test_order_does_not_matter_in_a_parallel_round(): void
    {
        $paper = $this->document();
        $signatures = $this->requests()->request($paper, [
            ['name' => 'First', 'email' => 'first@example.com'],
            ['name' => 'Second', 'email' => 'second@example.com'],
        ], 'parallel');

        $signed = $this->requests()->sign($signatures->last());

        $this->assertTrue($signed->isSigned());
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->requests()->request($this->document(), [['name' => 'A', 'email' => 'a@example.com']], 'whenever');
    }

    // ── Status ────────────────────────────────────────────────────────────

    public function test_status_before_any_signatures_are_requested(): void
    {
        $this->assertSame('not_requested', $this->requests()->status($this->document())['status']);
    }

    public function test_status_partway_through(): void
    {
        $paper = $this->document();
        $signatures = $this->requests()->request($paper, [
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
        ]);
        $this->requests()->sign($signatures->first());

        $status = $this->requests()->status($paper->fresh());

        $this->assertSame('partially_signed', $status['status']);
        $this->assertSame(1, $status['signed']);
        $this->assertSame(2, $status['total']);
    }

    public function test_status_once_declined(): void
    {
        $paper = $this->document();
        $signatures = $this->requests()->request($paper, [
            ['name' => 'A', 'email' => 'a@example.com'],
            ['name' => 'B', 'email' => 'b@example.com'],
        ]);
        $this->requests()->sign($signatures->first());
        $this->requests()->decline($signatures->last(), 'Changed my mind.');

        $this->assertSame('declined', $this->requests()->status($paper->fresh())['status']);
    }

    // ── Completion ────────────────────────────────────────────────────────

    public function test_full_completion_mints_a_verification_token(): void
    {
        $paper = $this->document();
        $signature = $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']])->first();

        $this->requests()->sign($signature);

        $this->assertNotNull($paper->fresh()->verification_token_id);
        $this->assertSame(
            \App\Models\BusinessDocument::class,
            $paper->fresh()->verificationToken->subject_type,
        );
    }

    public function test_full_completion_emits_document_signed(): void
    {
        $paper = $this->document();
        $signature = $this->requests()->request($paper, [['name' => 'A', 'email' => 'a@example.com']])->first();

        $this->requests()->sign($signature);

        $this->assertSame('fully_signed', $this->requests()->status($paper->fresh())['status']);
    }

    /** A signature round can be requested on an issued document — that is the common case. */
    public function test_an_issued_document_can_still_be_sent_for_signature(): void
    {
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();

        $signatures = $this->requests()->request($paper->fresh(), [['name' => 'A', 'email' => 'a@example.com']]);

        $this->assertSame(1, $signatures->count());
        $this->assertFalse($paper->fresh()->isTampered());
    }

    public function test_signing_an_issued_document_does_not_break_its_hash(): void
    {
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();
        $signature = $this->requests()->request($paper->fresh(), [['name' => 'A', 'email' => 'a@example.com']])->first();

        $this->requests()->sign($signature);

        $this->assertFalse($paper->fresh()->isTampered());
    }

    // ── Permissions ───────────────────────────────────────────────────────

    public function test_only_someone_who_may_share_may_request_signatures(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $cashier = $this->memberAt(Role::CASHIER);
        $paper = $this->document();

        $this->assertTrue($manager->can('share', $paper));
        $this->assertFalse($cashier->can('share', $paper));
    }

    protected function requests(): DocumentSignatureRequests
    {
        return app(DocumentSignatureRequests::class);
    }
}
