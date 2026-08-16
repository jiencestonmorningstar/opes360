<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentFolder;
use App\Models\User;
use App\Services\DocumentComposer;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * §64–68 of the master spec — bulk move, tag, classify, archive, download.
 *
 * Bulk here means the single-document path run N times, one transaction per
 * item, with the policy asked about each document individually — never a mass
 * UPDATE that would slip past the policy, the audit observer and the issued-
 * document guard all at once. Slower, and correct: a batch of forty documents
 * where the actor may file thirty-nine must file thirty-nine.
 *
 * An item that is refused — wrong permission, legal hold, not in a state the
 * single action would accept — is skipped and named in the report. The batch
 * never fails wholesale over one document, and never pretends the refused one
 * was done. The report is the contract: whoever ran the batch reads exactly
 * what happened to every document they selected.
 *
 * @phpstan-type BulkReport array{done: array<int, string>, refused: array<int, array{title: string, reason: string}>}
 */
class BulkActions
{
    public function __construct(
        protected DocumentComposer $composer,
        protected DocumentBundles $bundles,
    ) {}

    /**
     * File each document into a folder (or back to the root, when null).
     *
     * The same write LibraryController::update() performs for one document:
     * a guarded update of `folder_id` behind the `file` ability, which the
     * issued-document guard already permits because filing is not content.
     *
     * @param  Collection<int, BusinessDocument>  $documents
     * @return BulkReport
     */
    public function move(Collection $documents, ?BusinessDocumentFolder $folder, User $actor): array
    {
        return $this->each($documents, $actor, 'file', function (BusinessDocument $document) use ($folder) {
            $document->update(['folder_id' => $folder?->id]);
            $document->emitDomainEvent('document.updated', ['change' => 'moved', 'folder_id' => $folder?->id]);
        });
    }

    /**
     * Add one tag to each document, keeping whatever tags it already carries.
     *
     * @param  Collection<int, BusinessDocument>  $documents
     * @return BulkReport
     */
    public function tag(Collection $documents, string $tag, User $actor): array
    {
        $tag = trim($tag);

        if ($tag === '') {
            throw new RuntimeException('A tag needs a name.');
        }

        return $this->each($documents, $actor, 'file', function (BusinessDocument $document) use ($tag) {
            $tags = $document->tags ?? [];

            if (! in_array($tag, $tags, true)) {
                $document->update(['tags' => [...$tags, $tag]]);
                $document->emitDomainEvent('document.updated', ['change' => 'tagged', 'tag' => $tag]);
            }
        });
    }

    /**
     * Remove one tag from each document. A document that never had it is
     * simply left alone — that is success, not a refusal.
     *
     * @param  Collection<int, BusinessDocument>  $documents
     * @return BulkReport
     */
    public function untag(Collection $documents, string $tag, User $actor): array
    {
        $tag = trim($tag);

        return $this->each($documents, $actor, 'file', function (BusinessDocument $document) use ($tag) {
            $tags = $document->tags ?? [];

            if (in_array($tag, $tags, true)) {
                $document->update(['tags' => array_values(array_diff($tags, [$tag]))]);
                $document->emitDomainEvent('document.updated', ['change' => 'untagged', 'tag' => $tag]);
            }
        });
    }

    /**
     * Set the security classification on each document. Classification is a
     * filing field — the issued-document guard permits it, and the `file`
     * ability governs it, exactly as for one document at a time.
     *
     * @param  Collection<int, BusinessDocument>  $documents
     * @return BulkReport
     */
    public function classify(Collection $documents, string $security, User $actor): array
    {
        return $this->each($documents, $actor, 'file', function (BusinessDocument $document) use ($security) {
            $document->update(['security' => $security]);
            $document->emitDomainEvent('document.updated', ['change' => 'classified', 'security' => $security]);
        });
    }

    /**
     * Archive each document — which in this product means voiding it, the
     * same act DocumentComposer performs for one: the verification token is
     * revoked so a scan of the paper already handed over reports it void.
     *
     * Two refusals surface by name rather than being smoothed over: a
     * document under legal hold is not touched at all, and a draft is refused
     * by the composer itself because only an issued document can be voided.
     *
     * @param  Collection<int, BusinessDocument>  $documents
     * @return BulkReport
     */
    public function archive(Collection $documents, User $actor, ?string $reason = null): array
    {
        return $this->each($documents, $actor, 'void', function (BusinessDocument $document) use ($actor, $reason) {
            if ($document->isUnderLegalHold()) {
                throw new RuntimeException('it is under legal hold');
            }

            // The composer revokes the verification token; loading it up
            // front keeps this loop honest under the app's lazy-loading ban.
            $this->composer->void($document->loadMissing('verificationToken'), $actor, $reason ?? 'Archived in bulk');
            $document->emitDomainEvent('document.archived');
        });
    }

    /**
     * One ZIP of every selected document's stored file, skipping the ones the
     * actor may not read rather than leaking them into the archive. Documents
     * composed from a template have no stored file and are skipped by the
     * packager, exactly as a package download skips them. Never empty on
     * disk — ZipArchive writes nothing at all for an archive with no entries,
     * so the packager adds a README in that case.
     */
    public function zip(Collection $documents, User $actor): string
    {
        $readable = $documents->filter(fn (BusinessDocument $document) => Gate::forUser($actor)->allows('view', $document));

        return $this->bundles->zipDocuments($readable);
    }

    /**
     * The loop every bulk action shares: per item, ask the policy, run the
     * single-document act inside its own transaction, and record the outcome.
     * One document's refusal must never roll back another's success — hence
     * a transaction per item, not one around the batch.
     *
     * @param  Collection<int, BusinessDocument>  $documents
     * @param  Closure(BusinessDocument): void  $act
     * @return BulkReport
     */
    protected function each(Collection $documents, User $actor, string $ability, Closure $act): array
    {
        $report = ['done' => [], 'refused' => []];

        foreach ($documents as $document) {
            try {
                if (! Gate::forUser($actor)->allows($ability, $document)) {
                    throw new AuthorizationException('you may not '.($ability === 'void' ? 'archive' : $ability).' it');
                }

                DB::transaction(fn () => $act($document));

                $report['done'][] = $document->title;
            } catch (AuthorizationException $e) {
                $report['refused'][] = ['title' => $document->title, 'reason' => $e->getMessage()];
            } catch (RuntimeException $e) {
                $report['refused'][] = ['title' => $document->title, 'reason' => $e->getMessage()];
            }
        }

        return $report;
    }

    /**
     * The report as one honest sentence: "3 moved, 1 refused: Contract X — it
     * is under legal hold."
     *
     * @param  BulkReport  $report
     */
    public function summarise(array $report, string $pastVerb): string
    {
        $parts = [count($report['done']).' '.$pastVerb];

        if ($report['refused'] !== []) {
            $named = collect($report['refused'])
                ->map(fn (array $r) => $r['title'].' — '.$r['reason'])
                ->implode('; ');

            $parts[] = count($report['refused']).' refused: '.$named;
        }

        return implode(', ', $parts).'.';
    }
}
