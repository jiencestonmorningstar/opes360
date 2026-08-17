<?php

namespace App\Console\Commands;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentSignature;
use App\Models\NotificationDelivery;
use App\Models\WorkflowAssignment;
use App\Support\DocumentAnalytics;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * §2.15 — the daily nudge for everything still waiting on somebody.
 *
 * Three kinds of waiting: a workflow assignment past its step's due date, a
 * document inside its expiry window, and a signature request nobody has
 * answered. The command sends nothing itself — it raises domain events, and
 * the business's notification rules decide who is told, on which channels,
 * with mutes, quiet hours, digests and dedupe all applied by the one
 * dispatcher every other notification already passes through. A sweep that
 * mailed people directly would be the second sender the dispatcher exists to
 * prevent.
 *
 * Once per subject per day, whatever the rule's own dedupe says: before
 * raising an event the sweep checks the dispatcher's delivery log for the same
 * event about the same record today. That log is the fingerprint the
 * dispatcher already writes — reusing it means a re-run (or a second server)
 * cannot double-remind, without inventing a second bookkeeping table.
 *
 * Runs with no tenant in scope; every event carries its company from the row
 * that raised it. A day with nothing due raises nothing at all.
 */
class RemindOutstandingActions extends Command
{
    protected $signature = 'opes:remind-actions
        {--signature-days=3 : Days a signature request may sit unanswered before it is chased}';

    protected $description = 'Raise reminder events for overdue approvals, expiring documents and unanswered signature requests';

    public function handle(): int
    {
        $raised = $this->overdueAssignments()
            + $this->expiringDocuments()
            + $this->expiredDocuments()
            + $this->staleSignatureRequests();

        $this->info("{$raised} reminder(s) raised.");

        return self::SUCCESS;
    }

    protected function overdueAssignments(): int
    {
        /*
         * Strictly before today, compared as dates. `due_on` is a date cast —
         * midnight — so an instant comparison would call an assignment
         * "overdue" from 00:01 on the very day it is due.
         */
        $assignments = WorkflowAssignment::query()
            ->acrossAllCompanies()
            ->pending()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', now()->toDateString())
            ->with('instance')
            ->get();

        $raised = 0;

        foreach ($assignments as $assignment) {
            if ($this->announcedToday('workflow.assignment.overdue', $assignment)) {
                continue;
            }

            $assignment->emitDomainEvent('workflow.assignment.overdue', [
                'assignment_id' => $assignment->id,
                'workflow_instance_id' => $assignment->workflow_instance_id,
                'user_id' => $assignment->user_id,
                'due_on' => $assignment->due_on?->toDateString(),
                'subject_type' => $assignment->instance?->subject_type,
                'subject_id' => $assignment->instance?->subject_id,
            ]);

            $raised++;
        }

        return $raised;
    }

    protected function expiringDocuments(): int
    {
        // The same horizon as the workspace's "Expiring" counter, so the
        // reminder and the screen never disagree about what "soon" means.
        $documents = BusinessDocument::query()
            ->acrossAllCompanies()
            ->where('status', '!=', 'void')
            ->expiringWithin(DocumentAnalytics::EXPIRY_HORIZON_DAYS)
            ->get();

        $raised = 0;

        foreach ($documents as $document) {
            if ($this->announcedToday('document.expiring', $document)) {
                continue;
            }

            $document->emitDomainEvent('document.expiring', [
                'expires_on' => $document->expires_on?->toDateString(),
                'days_left' => (int) now()->startOfDay()
                    ->diffInDays($document->expires_on->startOfDay()),
            ]);

            $raised++;
        }

        return $raised;
    }

    /**
     * A document that has now actually lapsed — as opposed to expiringDocuments()
     * above, which is still inside its warning window. Announced once ever
     * rather than once a day: expiring is a countdown a rule may want nudged
     * daily, but lapsing is a single transition, and re-raising it every day
     * forever would make `document.expired` indistinguishable from a mute
     * that never stopped ringing.
     */
    protected function expiredDocuments(): int
    {
        $documents = BusinessDocument::query()
            ->acrossAllCompanies()
            ->where('status', '!=', 'void')
            ->expired()
            ->get();

        $raised = 0;

        foreach ($documents as $document) {
            if ($this->everAnnounced('document.expired', $document)) {
                continue;
            }

            $document->emitDomainEvent('document.expired', [
                'expired_on' => $document->expires_on?->toDateString(),
            ]);

            $raised++;
        }

        return $raised;
    }

    protected function staleSignatureRequests(): int
    {
        $days = max(1, (int) $this->option('signature-days'));

        $signatures = BusinessDocumentSignature::query()
            ->acrossAllCompanies()
            ->pending()
            ->where('created_at', '<=', now()->subDays($days))
            ->with('document')
            ->get();

        $raised = 0;

        // Grouped per document: three slow signers on one contract are one
        // piece of news, not three.
        foreach ($signatures->groupBy('business_document_id') as $waiting) {
            $document = $waiting->first()->document;

            if ($document === null || $document->status === 'void') {
                continue;
            }

            if ($this->announcedToday('document.signature.overdue', $document)) {
                continue;
            }

            $document->emitDomainEvent('document.signature.overdue', [
                'pending_signatures' => $waiting->count(),
                'signers' => $waiting->pluck('signer_name')->all(),
                'requested_at' => $waiting->min('created_at')?->toIso8601String(),
            ]);

            $raised++;
        }

        return $raised;
    }

    /**
     * Has the dispatcher already logged this news about this record today?
     *
     * Any delivery row counts — sent, deferred into a digest, or suppressed
     * by a mute — because each means a rule already heard today's event.
     */
    protected function announcedToday(string $event, Model $subject): bool
    {
        return NotificationDelivery::query()
            ->withoutGlobalScopes()
            ->where('event', $event)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
    }

    /** Has the dispatcher ever logged this news about this record, any day? */
    protected function everAnnounced(string $event, Model $subject): bool
    {
        return NotificationDelivery::query()
            ->withoutGlobalScopes()
            ->where('event', $event)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->exists();
    }
}
