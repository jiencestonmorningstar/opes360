<?php

namespace Tests\Feature\Documents;

use App\Models\ActivityLog;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentShare;
use App\Support\Watermarks;

/**
 * §2.17 — watermarks and print control.
 *
 * The status marks (DRAFT / VOID / COPY) are deliberately not optional and no
 * setting exists to turn them off: a watermark a business can switch off is a
 * watermark that will be off on exactly the printout that ends up in dispute.
 * A draft that prints clean is indistinguishable from an issued document, and
 * a voided one that prints clean is a live instrument again. Only the
 * confidential footer is a preference — it marks provenance, not validity.
 */
class WatermarkTest extends DocumentsTestCase
{
    public function test_a_draft_prints_with_a_draft_watermark(): void
    {
        $paper = $this->document(['status' => 'draft']);

        $response = $this->actingAs($this->owner)->get(route('papers.print', $paper));

        $response->assertOk();
        $response->assertSee('wm-status', false);
        $response->assertSee('>DRAFT<', false);
    }

    public function test_a_voided_document_prints_with_a_void_watermark(): void
    {
        $paper = $this->issued(['voided_at' => now(), 'void_reason' => 'Superseded']);
        $paper->update(['status' => 'void']);

        $response = $this->actingAs($this->owner)->get(route('papers.print', $paper));

        $response->assertOk();
        $response->assertSee('>VOID<', false);
    }

    public function test_an_issued_document_prints_clean(): void
    {
        $paper = $this->issued();

        $response = $this->actingAs($this->owner)->get(route('papers.print', $paper));

        $response->assertOk();
        $response->assertDontSee('wm-status', false);
        $response->assertDontSee('>DRAFT<', false);
        $response->assertDontSee('>VOID<', false);
    }

    public function test_a_confidential_print_names_its_viewer_and_company(): void
    {
        $paper = $this->issued(['security' => 'confidential']);

        $response = $this->actingAs($this->owner)->get(route('papers.print', $paper));

        $response->assertOk();
        $response->assertSee('CONFIDENTIEL — '.$this->company->name);
        $response->assertSee($this->owner->name);
    }

    public function test_a_confidential_print_writes_an_export_audit_row(): void
    {
        $paper = $this->issued(['security' => 'restricted']);

        $this->actingAs($this->owner)->get(route('papers.print', $paper))->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'event' => 'exported',
            'subject_type' => BusinessDocument::class,
            'subject_id' => $paper->id,
        ]);
    }

    public function test_printing_the_same_confidential_paper_twice_writes_two_rows(): void
    {
        // Export-tier, never windowed: two prints are two copies in the world.
        $paper = $this->issued(['security' => 'confidential']);

        $this->actingAs($this->owner)->get(route('papers.print', $paper));
        $this->actingAs($this->owner)->get(route('papers.print', $paper));

        $this->assertSame(2, ActivityLog::query()
            ->where('event', 'exported')
            ->where('subject_id', $paper->id)
            ->count());
    }

    public function test_an_internal_print_carries_no_confidential_footer_and_no_export_row(): void
    {
        $paper = $this->issued(['security' => 'internal']);

        $response = $this->actingAs($this->owner)->get(route('papers.print', $paper));

        $response->assertOk();
        $response->assertDontSee('CONFIDENTIEL');
        $this->assertDatabaseMissing('activity_log', ['event' => 'exported', 'subject_id' => $paper->id]);
    }

    public function test_the_company_toggle_removes_the_footer_but_never_the_status_mark(): void
    {
        $this->company->forceFill(['prints_confidential_footer' => false])->save();

        $paper = $this->document(['status' => 'draft', 'security' => 'confidential']);

        $response = $this->actingAs($this->owner)->get(route('papers.print', $paper));

        $response->assertOk();
        $response->assertDontSee('CONFIDENTIEL');
        // The status mark survives the toggle: validity marks are not a preference.
        $response->assertSee('>DRAFT<', false);
    }

    public function test_a_shared_issued_document_prints_as_a_copy_with_the_share_identity(): void
    {
        $paper = $this->issued(['security' => 'confidential']);
        $share = $paper->shares()->create([
            'created_by' => $this->owner->id,
            'share_token' => BusinessDocumentShare::newShareToken(),
            'allow_download' => true,
        ]);

        $response = $this->get(route('shares.show', $share->share_token));

        $response->assertOk();
        // A shared print is a copy, never the original.
        $response->assertSee('>COPY<', false);
        $response->assertSee('CONFIDENTIEL — '.$this->company->name);
        // The share identity — not any user's name — marks the external copy.
        $response->assertSee(Watermarks::shareIdentity($share));
        $response->assertDontSee($this->owner->name);
    }

    public function test_a_shared_draft_still_says_draft_not_copy(): void
    {
        $paper = $this->document(['status' => 'draft']);
        $share = $paper->shares()->create([
            'created_by' => $this->owner->id,
            'share_token' => BusinessDocumentShare::newShareToken(),
            'allow_download' => true,
        ]);

        $response = $this->get(route('shares.show', $share->share_token));

        $response->assertOk();
        $response->assertSee('>DRAFT<', false);
        $response->assertDontSee('>COPY<', false);
    }

    /** An issued paper, made the way IssueDocument does it — reference and issued_at set. */
    protected function issued(array $attributes = []): BusinessDocument
    {
        return $this->document(array_merge([
            'status' => 'issued',
            'issued_at' => now(),
        ], $attributes));
    }
}
