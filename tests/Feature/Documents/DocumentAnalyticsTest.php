<?php

namespace Tests\Feature\Documents;

use App\Livewire\Papers\Analytics;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentFolder;
use App\Models\BusinessDocumentShare;
use App\Models\BusinessDocumentShareAccess;
use App\Models\BusinessDocumentSignature;
use App\Models\Company;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowDecision;
use App\Models\WorkflowInstance;
use App\Support\DocumentAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Documents plan phase 2.19 — the analytics read model.
 *
 * Every assertion here compares the read model's figure to what a direct
 * query says, on the Kpis principle: a dashboard figure that disagrees with
 * the table behind it costs the reader their trust in both.
 */
class DocumentAnalyticsTest extends DocumentsTestCase
{
    protected function analytics(): DocumentAnalytics
    {
        return new DocumentAnalytics(
            CarbonImmutable::now()->subDays(30),
            CarbonImmutable::now(),
        );
    }

    public function test_created_and_issued_counts_equal_direct_queries(): void
    {
        $this->document(['kind' => 'contract']);
        $this->document(['kind' => 'contract']);
        $this->document(['kind' => 'letter']);

        $issued = $this->document(['kind' => 'contract']);
        $issued->forceFill(['status' => 'issued', 'issued_at' => now()->subDay()])->save();

        $summary = $this->analytics()->summary();

        $this->assertSame(BusinessDocument::query()->count(), $summary['created']);
        $this->assertSame(1, $summary['issued']);

        $byKind = collect($this->analytics()->byKind())->keyBy('kind');
        $this->assertSame(3, $byKind['contract']['created']);
        $this->assertSame(1, $byKind['contract']['issued']);
        $this->assertSame(1, $byKind['letter']['created']);
        $this->assertSame(0, $byKind['letter']['issued']);
    }

    /** The date-cast midnight trap: issued late on the window's last day still counts. */
    public function test_a_document_issued_late_on_the_final_day_is_counted(): void
    {
        $paper = $this->document();
        $paper->forceFill(['status' => 'issued', 'issued_at' => now()->endOfDay()->subMinutes(10)])->save();

        $analytics = new DocumentAnalytics(
            CarbonImmutable::now()->subDays(7)->startOfDay(),
            CarbonImmutable::now()->startOfDay(), // a bare "today at midnight" bound
        );

        $this->assertSame(1, $analytics->summary()['issued']);
    }

    public function test_average_draft_to_issue_is_computed_from_the_timestamps(): void
    {
        $fast = $this->document();
        BusinessDocument::withoutEvents(fn () => $fast->forceFill([
            'created_at' => now()->subDays(4),
            'status' => 'issued',
            'issued_at' => now()->subDays(2), // held 2 days
        ])->save());

        $slow = $this->document();
        BusinessDocument::withoutEvents(fn () => $slow->forceFill([
            'created_at' => now()->subDays(10),
            'status' => 'issued',
            'issued_at' => now()->subDays(4), // held 6 days
        ])->save());

        $this->assertEqualsWithDelta(4.0, $this->analytics()->summary()['draft_to_issue_days'], 0.1);
    }

    public function test_the_bottleneck_step_is_the_one_that_actually_held_longest(): void
    {
        $paper = $this->document();
        $workflow = $this->twoStepWorkflow();
        [$first, $second] = $workflow->steps->sortBy('position')->values();

        $instance = WorkflowInstance::create([
            'workflow_id' => $workflow->id,
            'subject_type' => $paper->getMorphClass(),
            'subject_id' => $paper->id,
            'status' => 'approved',
            'position' => 2,
            'started_at' => now()->subDays(10),
            'completed_at' => now()->subDays(1),
        ]);

        // Quick review held 2 hours; Director sign-off held ~9 days.
        WorkflowDecision::create([
            'workflow_instance_id' => $instance->id,
            'workflow_step_id' => $first->id,
            'step_name' => 'Quick review',
            'action' => 'approved',
            'acted_at' => now()->subDays(10)->addHours(2),
        ]);
        WorkflowDecision::create([
            'workflow_instance_id' => $instance->id,
            'workflow_step_id' => $second->id,
            'step_name' => 'Director sign-off',
            'action' => 'approved',
            'acted_at' => now()->subDays(1),
        ]);

        $bottlenecks = $this->analytics()->bottlenecks();

        $this->assertSame('Director sign-off', $bottlenecks[0]['step']);
        $this->assertGreaterThan($bottlenecks[1]['avg_hours'], $bottlenecks[0]['avg_hours']);
    }

    public function test_signature_completion_rate_and_time_to_sign(): void
    {
        $paper = $this->document();

        BusinessDocumentSignature::create([
            'business_document_id' => $paper->id,
            'signer_name' => 'A', 'signer_email' => 'a@example.com',
            'order' => 1, 'status' => 'signed',
            'signing_token' => BusinessDocumentSignature::newSigningToken(),
            'created_at' => now()->subDays(3),
            'signed_at' => now()->subDays(2), // 24 hours to sign
        ]);
        BusinessDocumentSignature::create([
            'business_document_id' => $paper->id,
            'signer_name' => 'B', 'signer_email' => 'b@example.com',
            'order' => 2, 'status' => 'pending',
            'signing_token' => BusinessDocumentSignature::newSigningToken(),
        ]);

        $summary = $this->analytics()->summary();

        $this->assertSame(50.0, $summary['signature_rate']);
        $this->assertEqualsWithDelta(24.0, $summary['signature_hours'], 0.5);
    }

    public function test_share_opens_are_counted_and_the_most_opened_paper_leads(): void
    {
        $quiet = $this->document(['title' => 'Quiet memo']);
        $busy = $this->document(['title' => 'Popular contract']);

        $this->open($this->share($quiet), 1);
        $this->open($this->share($busy), 3);

        $summary = $this->analytics()->summary();
        $this->assertSame(4, $summary['share_opens']);

        $top = $this->analytics()->mostShared();
        $this->assertSame('Popular contract', $top[0]['label']);
        $this->assertSame(3, $top[0]['opens']);
    }

    /** A restricted paper contributes to every count, but its title appears nowhere. */
    public function test_a_restricted_papers_title_appears_nowhere_in_the_payload(): void
    {
        $restricted = $this->document([
            'title' => 'Board dismissal deliberations',
            'security' => 'restricted',
            'kind' => 'minutes',
        ]);
        $this->open($this->share($restricted), 5);

        $analytics = $this->analytics();

        // It counts…
        $this->assertSame(1, $analytics->summary()['created']);
        $this->assertSame(5, $analytics->summary()['share_opens']);
        $this->assertSame(5, $analytics->mostShared()[0]['opens']);
        $this->assertTrue($analytics->mostShared()[0]['restricted']);

        // …but the title travels nowhere.
        $payload = json_encode([
            $analytics->summary(),
            $analytics->byKind(),
            $analytics->bottlenecks(),
            $analytics->mostShared(),
            $analytics->storageByFolder(),
        ]);

        $this->assertStringNotContainsString('Board dismissal deliberations', $payload);
    }

    public function test_expiring_soon_counts_what_the_scope_counts(): void
    {
        $this->document(['expires_on' => now()->addDays(10)->toDateString()]);
        $this->document(['expires_on' => now()->addDays(400)->toDateString()]);
        $this->document(['expires_on' => now()->subDay()->toDateString()]);

        $this->assertSame(
            BusinessDocument::query()->expiringWithin(30)->count(),
            $this->analytics()->summary()['expiring_soon'],
        );
        $this->assertSame(1, $this->analytics()->summary()['expiring_soon']);
    }

    public function test_storage_is_summed_by_folder(): void
    {
        $folder = BusinessDocumentFolder::create(['name' => 'Contracts', 'kind' => BusinessDocumentFolder::COMPANY]);

        $filed = $this->document(['folder_id' => $folder->id]);
        $loose = $this->document();

        $this->attachFile($filed, 2048);
        $this->attachFile($filed, 1024);
        $this->attachFile($loose, 512);

        $rows = collect($this->analytics()->storageByFolder())->keyBy('folder');

        $this->assertSame(3072, $rows['Contracts']['bytes']);
        $this->assertSame(512, $rows['Unfiled']['bytes']);
    }

    public function test_no_aggregate_crosses_tenants(): void
    {
        $this->document(['kind' => 'contract']);

        // A busy neighbouring company.
        $stranger = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $stranger->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $foreign = BusinessDocument::create([
            'company_id' => $other->id,
            'template' => 'service_agreement',
            'title' => 'Foreign paper',
            'body' => 'x',
            'status' => 'draft',
        ]);
        BusinessDocumentSignature::create([
            'company_id' => $other->id,
            'business_document_id' => $foreign->id,
            'signer_name' => 'X', 'signer_email' => 'x@example.com',
            'order' => 1, 'status' => 'signed',
            'signing_token' => BusinessDocumentSignature::newSigningToken(),
            'signed_at' => now(),
        ]);
        $foreignShare = BusinessDocumentShare::create([
            'company_id' => $other->id,
            'business_document_id' => $foreign->id,
            'share_token' => BusinessDocumentShare::newShareToken(),
        ]);
        BusinessDocumentShareAccess::create([
            'business_document_share_id' => $foreignShare->id,
            'viewed_at' => now(),
        ]);
        Media::create([
            'company_id' => $other->id,
            'collection' => 'document',
            'disk' => 'documents',
            'path' => 'c/x/file.pdf',
            'size' => 9999,
            'attachable_type' => $foreign->getMorphClass(),
            'attachable_id' => $foreign->id,
        ]);

        $summary = $this->analytics()->summary();

        $this->assertSame(1, $summary['created']);
        $this->assertNull($summary['signature_rate']);
        $this->assertSame(0, $summary['share_opens']);
        $this->assertSame([], $this->analytics()->storageByFolder());
    }

    // ── The screen ──────────────────────────────────────────────────────

    public function test_the_screen_is_open_to_a_holder_of_papers_manage(): void
    {
        Livewire::actingAs($this->memberAt(Role::ADMINISTRATOR))
            ->test(Analytics::class)
            ->assertOk();
    }

    public function test_the_screen_is_refused_without_papers_manage(): void
    {
        Livewire::actingAs($this->memberAt(Role::MANAGER))
            ->test(Analytics::class)
            ->assertForbidden();
    }

    public function test_the_screen_never_prints_a_restricted_title(): void
    {
        $restricted = $this->document([
            'title' => 'Board dismissal deliberations',
            'security' => 'restricted',
        ]);
        $this->open($this->share($restricted), 2);

        Livewire::actingAs($this->owner)
            ->test(Analytics::class)
            ->assertOk()
            ->assertDontSee('Board dismissal deliberations');
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    protected function share(BusinessDocument $paper): BusinessDocumentShare
    {
        return BusinessDocumentShare::create([
            'business_document_id' => $paper->id,
            'share_token' => BusinessDocumentShare::newShareToken(),
            'created_by' => $this->owner->id,
        ]);
    }

    protected function open(BusinessDocumentShare $share, int $times): void
    {
        foreach (range(1, $times) as $i) {
            BusinessDocumentShareAccess::create([
                'business_document_share_id' => $share->id,
                'viewed_at' => now()->subHours($i),
            ]);
        }
    }

    protected function attachFile(BusinessDocument $paper, int $bytes): Media
    {
        return Media::create([
            'collection' => 'document',
            'disk' => 'documents',
            'path' => 'c/'.$this->company->id.'/'.Str::random(10).'.pdf',
            'mime' => 'application/pdf',
            'size' => $bytes,
            'attachable_type' => $paper->getMorphClass(),
            'attachable_id' => $paper->id,
        ]);
    }

    protected function twoStepWorkflow(): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Two-step approval',
            'subject_type' => BusinessDocument::class,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'position' => 1, 'name' => 'Quick review', 'type' => 'approval',
            'approver_mode' => 'role', 'approver_role' => Role::MANAGER, 'quorum' => 'any',
        ]);
        $workflow->steps()->create([
            'position' => 2, 'name' => 'Director sign-off', 'type' => 'approval',
            'approver_mode' => 'role', 'approver_role' => Role::ADMINISTRATOR, 'quorum' => 'any',
        ]);

        return $workflow->fresh();
    }
}
