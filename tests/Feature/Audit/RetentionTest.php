<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\Governance;
use App\Models\ActivityLog;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Support\AuditRetention;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The trail's own retention: floors that cannot be undercut, money rows that
 * outlive chatter, a pruning that records itself, and a legal hold that wins.
 */
class RetentionTest extends AuditTestCase
{
    /** Write a raw trail row aged $months back, for $company. */
    protected function row(Company $company, string $event, int $months, ?string $subjectType = null, ?string $subjectId = null): ActivityLog
    {
        return ActivityLog::create([
            'company_id' => $company->id,
            'user_id' => $this->user->id,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => 'aged row',
            'created_at' => Carbon::now()->subMonths($months),
        ]);
    }

    public function test_the_twelve_month_floor_cannot_be_undercut_by_the_company_setting(): void
    {
        $this->company->forceFill(['audit_retention_months' => 1])->save();

        $young = $this->row($this->company, 'accessed', 11);
        $old = $this->row($this->company, 'accessed', 13);

        $this->artisan('opes:prune-audit')->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['id' => $young->id]);
        $this->assertDatabaseMissing('activity_log', ['id' => $old->id]);
    }

    public function test_general_write_rows_keep_their_own_higher_floor(): void
    {
        $this->company->forceFill(['audit_retention_months' => 12])->save();

        // A write about an ordinary subject: floor is the general one, not 12.
        $write = $this->row($this->company, 'updated', AuditRetention::FLOOR_GENERAL_MONTHS - 1, 'App\Models\Contact', '01X');
        $stale = $this->row($this->company, 'updated', AuditRetention::FLOOR_GENERAL_MONTHS + 1, 'App\Models\Contact', '01Y');

        $this->artisan('opes:prune-audit')->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['id' => $write->id]);
        $this->assertDatabaseMissing('activity_log', ['id' => $stale->id]);
    }

    public function test_money_rows_outlive_access_rows(): void
    {
        // Both thirty months old; the read goes, the payment row stays.
        $read = $this->row($this->company, 'accessed', 30, 'App\Models\Payslip', '01A');
        $money = $this->row($this->company, 'updated', 30, 'App\Models\Payment', '01B');
        $export = $this->row($this->company, 'exported', 30);
        $grant = $this->row($this->company, 'permission-changed', 30, 'App\Models\User', '1');

        $this->artisan('opes:prune-audit')->assertSuccessful();

        $this->assertDatabaseMissing('activity_log', ['id' => $read->id]);
        $this->assertDatabaseHas('activity_log', ['id' => $money->id]);
        $this->assertDatabaseHas('activity_log', ['id' => $export->id]);
        $this->assertDatabaseHas('activity_log', ['id' => $grant->id]);
    }

    public function test_money_rows_do_prune_after_the_financial_floor(): void
    {
        $money = $this->row($this->company, 'updated', AuditRetention::FLOOR_FINANCIAL_MONTHS + 1, 'App\Models\Payment', '01B');

        $this->artisan('opes:prune-audit')->assertSuccessful();

        $this->assertDatabaseMissing('activity_log', ['id' => $money->id]);
    }

    public function test_pruning_writes_one_summary_row_that_itself_survives_pruning(): void
    {
        $this->row($this->company, 'accessed', 30);
        $this->row($this->company, 'accessed', 31);

        // A summary from an earlier pruning, itself far past every cutoff.
        $earlier = $this->row($this->company, AuditRetention::SUMMARY_EVENT, 40);

        $this->artisan('opes:prune-audit')->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['id' => $earlier->id]);

        $summary = ActivityLog::query()
            ->where('company_id', $this->company->id)
            ->where('event', AuditRetention::SUMMARY_EVENT)
            ->whereKeyNot($earlier->id)
            ->first();

        $this->assertNotNull($summary, 'Pruning must record itself in the trail.');
        $this->assertSame(2, $summary->properties['pruned']);
        $this->assertArrayHasKey('policy', $summary->properties);
    }

    public function test_no_summary_row_is_written_when_nothing_was_pruned(): void
    {
        $this->row($this->company, 'accessed', 1);

        $this->artisan('opes:prune-audit')->assertSuccessful();

        $this->assertSame(0, ActivityLog::where('event', AuditRetention::SUMMARY_EVENT)->count());
    }

    public function test_legal_hold_blocks_pruning_of_a_documents_rows(): void
    {
        $held = BusinessDocument::create([
            'company_id' => $this->company->id,
            'template' => 'letter',
            'title' => 'Disputed agreement',
            'body' => 'Terms.',
            'status' => 'issued',
            'legal_hold' => true,
            'legal_hold_reason' => 'Litigation',
        ]);

        $free = BusinessDocument::create([
            'company_id' => $this->company->id,
            'template' => 'letter',
            'title' => 'Ordinary letter',
            'body' => 'Terms.',
            'status' => 'issued',
        ]);

        $heldRow = $this->row($this->company, 'accessed', 30, BusinessDocument::class, $held->id);
        $freeRow = $this->row($this->company, 'accessed', 30, BusinessDocument::class, $free->id);

        $this->artisan('opes:prune-audit')->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['id' => $heldRow->id]);
        $this->assertDatabaseMissing('activity_log', ['id' => $freeRow->id]);
    }

    public function test_pretend_mode_deletes_nothing_and_writes_no_summary(): void
    {
        $old = $this->row($this->company, 'accessed', 30);

        $this->artisan('opes:prune-audit', ['--pretend' => true])->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['id' => $old->id]);
        $this->assertSame(0, ActivityLog::where('event', AuditRetention::SUMMARY_EVENT)->count());
    }

    public function test_each_company_prunes_under_its_own_policy(): void
    {
        // The rival keeps everything for twenty years; we keep the default.
        $this->otherCompany->forceFill(['audit_retention_months' => 240])->save();

        $mine = $this->row($this->company, 'accessed', 30);
        $theirs = $this->row($this->otherCompany, 'accessed', 30);

        $this->artisan('opes:prune-audit')->assertSuccessful();

        $this->assertDatabaseMissing('activity_log', ['id' => $mine->id]);
        $this->assertDatabaseHas('activity_log', ['id' => $theirs->id]);
    }

    public function test_rows_for_deleted_records_still_prune(): void
    {
        // Subject long gone; the row must still age out on its own date.
        $orphan = $this->row($this->company, 'deleted', 30, 'App\Models\Contact', '01HZZZZZZZZZZZZZZZZZZZZZZZ');

        $this->artisan('opes:prune-audit')->assertSuccessful();

        $this->assertDatabaseMissing('activity_log', ['id' => $orphan->id]);
    }

    public function test_the_setting_floor_is_enforced_on_the_governance_screen(): void
    {
        Livewire::actingAs($this->user)
            ->test(Governance::class)
            ->set('retentionMonths', 6)
            ->call('saveRetention')
            ->assertHasErrors(['retentionMonths']);

        $this->assertNull($this->company->fresh()->audit_retention_months);

        Livewire::actingAs($this->user)
            ->test(Governance::class)
            ->set('retentionMonths', 36)
            ->call('saveRetention')
            ->assertHasNoErrors();

        $this->assertSame(36, (int) $this->company->fresh()->audit_retention_months);
    }
}
