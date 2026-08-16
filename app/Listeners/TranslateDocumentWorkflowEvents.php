<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\BusinessDocument;

/**
 * Restates a generic workflow.* event as the document.* name the master spec
 * catalogues, when the subject is a document.
 *
 * The workflow engine already emits workflow.started / .approved / .rejected
 * / .changes_requested for every approvable model — expenses, projects,
 * documents alike — because one engine serving every module is the entire
 * point of it. But an automation rule scoped to "any document event" needs
 * document.* names to match against, and teaching the engine itself to know
 * document-specific vocabulary would be the module coupling the engine was
 * built to avoid. A translator here keeps that knowledge out of the engine
 * and out of BusinessDocument, in the one place that already has to know
 * about both.
 */
class TranslateDocumentWorkflowEvents
{
    protected const MAP = [
        'workflow.started' => 'document.submitted',
        'workflow.approved' => 'document.approved',
        'workflow.rejected' => 'document.rejected',
        'workflow.changes_requested' => 'document.changes.requested',
    ];

    public function handle(DomainEvent $event): void
    {
        if (! $event->subject instanceof BusinessDocument) {
            return;
        }

        $translated = self::MAP[$event->name] ?? null;

        if ($translated === null) {
            return;
        }

        // Same event, new name — not re-dispatched through the model, which
        // would re-run BusinessDocument's own booted() hooks for an event
        // that already happened and needs no further side effects here.
        DomainEvent::dispatch($translated, $event->companyId, $event->subject, $event->context, $event->actorId);
    }
}
