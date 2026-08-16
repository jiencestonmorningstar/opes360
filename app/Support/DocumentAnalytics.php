<?php

namespace App\Support;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentFolder;
use App\Models\BusinessDocumentShare;
use App\Models\BusinessDocumentShareAccess;
use App\Models\BusinessDocumentSignature;
use App\Models\Media;
use App\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The documents dashboard's read model — one period, computed on demand.
 *
 * Built on the same rule as Kpis: every figure comes from the query that owns
 * it. Created/issued counts come off business_documents itself, share opens
 * off the share access log, bottlenecks off the workflow engine's own
 * decisions, storage off the media rows DocumentFiler wrote. Nothing is
 * stored, because a stored analytic is wrong by the time anybody opens it.
 *
 * Tenancy is the BelongsToCompany global scope throughout. The one table with
 * no company column of its own — share accesses — is only ever reached through
 * a subquery on shares, which are scoped; a test asserts no aggregate here can
 * see a neighbouring tenant.
 *
 * Averages are computed in PHP off the timestamps rather than in SQL:
 * SQLite (tests) and MySQL (production) do not share a date-arithmetic
 * dialect, and a figure that only exists on one of them is untestable.
 *
 * What this deliberately does NOT count:
 *  - Composed body text as "storage". Bodies live in database rows, not on the
 *    documents disk; adding their character counts to file bytes would be
 *    adding metres to litres. Storage here means filed binaries.
 *  - Restricted documents' titles. They contribute to every count — an
 *    analytics screen that undercounted would be lying — but the label shown
 *    for one is generic. See mostShared().
 */
class DocumentAnalytics
{
    /** How far ahead "expiring soon" looks, matching the workspace counter. */
    public const EXPIRY_HORIZON_DAYS = 30;

    protected CarbonImmutable $start;

    protected CarbonImmutable $end;

    public function __construct(CarbonImmutable $from, CarbonImmutable $to)
    {
        // Bounded on instants, whole days. issued_at and viewed_at are full
        // timestamps, so a bare "today" upper bound would silently drop
        // everything that happened after midnight this morning.
        $this->start = $from->startOfDay();
        $this->end = $to->endOfDay();
    }

    /**
     * The headline figures.
     *
     * @return array{
     *     created: int,
     *     issued: int,
     *     draft_to_issue_days: float|null,
     *     signature_rate: float|null,
     *     signature_hours: float|null,
     *     share_opens: int,
     *     expiring_soon: int
     * }
     */
    public function summary(): array
    {
        return [
            'created' => BusinessDocument::query()
                ->whereBetween('created_at', [$this->start, $this->end])->count(),
            'issued' => BusinessDocument::query()
                ->whereBetween('issued_at', [$this->start, $this->end])->count(),
            'draft_to_issue_days' => $this->averageDraftToIssueDays(),
            ...$this->signatureStats(),
            'share_opens' => $this->shareAccessQuery()->count(),
            // A count of right now, not of the window: "what will lapse" does
            // not reset because somebody looked at last month.
            'expiring_soon' => BusinessDocument::query()
                ->expiringWithin(self::EXPIRY_HORIZON_DAYS)->count(),
        ];
    }

    /**
     * Documents created and issued in the period, per kind, busiest first.
     *
     * Two grouped counts merged in PHP rather than one clever conditional-sum
     * query — created-in-window and issued-in-window are different row sets
     * (a document created last quarter can be issued this one), and portable
     * SQL for the union costs more than it saves.
     *
     * @return array<int, array{kind: string, label: string, created: int, issued: int}>
     */
    public function byKind(): array
    {
        $created = BusinessDocument::query()
            ->whereBetween('created_at', [$this->start, $this->end])
            ->selectRaw('kind, COUNT(*) as n')
            ->groupBy('kind')
            ->pluck('n', 'kind');

        $issued = BusinessDocument::query()
            ->whereBetween('issued_at', [$this->start, $this->end])
            ->selectRaw('kind, COUNT(*) as n')
            ->groupBy('kind')
            ->pluck('n', 'kind');

        return collect($created->keys())
            ->merge($issued->keys())
            ->unique()
            ->map(fn ($kind) => [
                // Rows whose kind was never set predate the classification
                // columns; they still have to count somewhere.
                'kind' => $kind ?? 'document',
                'label' => DocumentKinds::label($kind),
                'created' => (int) ($created[$kind] ?? 0),
                'issued' => (int) ($issued[$kind] ?? 0),
            ])
            ->sortByDesc(fn (array $row) => $row['created'] + $row['issued'])
            ->values()
            ->all();
    }

    /**
     * Which workflow step holds documents longest, worst first.
     *
     * Read from the engine's own record: the time a step held a document is
     * the gap between the event that put it there (the run starting, or the
     * previous decision) and the decision that moved it on — and for a run
     * still waiting, the gap to now. Grouped by the step's copied name, the
     * same name WorkflowDecision preserves against renames.
     *
     * @return array<int, array{step: string, avg_hours: float, count: int}>
     */
    public function bottlenecks(): array
    {
        $instances = WorkflowInstance::query()
            ->where('subject_type', (new BusinessDocument)->getMorphClass())
            ->whereBetween('started_at', [$this->start, $this->end])
            ->with(['decisions', 'workflow.steps'])
            ->get();

        /** @var array<string, array{seconds: float, count: int}> $held */
        $held = [];
        $record = function (string $step, float $seconds) use (&$held): void {
            $held[$step] ??= ['seconds' => 0.0, 'count' => 0];
            $held[$step]['seconds'] += $seconds;
            $held[$step]['count']++;
        };

        foreach ($instances as $instance) {
            $enteredAt = $instance->started_at;

            foreach ($instance->decisions as $decision) {
                // 'submitted' is the run starting, not a step concluding —
                // counting it would credit the first step with a zero-length
                // stay it never had.
                if ($decision->action !== 'submitted' && $enteredAt !== null) {
                    $record($decision->step_name, max(0, $enteredAt->diffInSeconds($decision->acted_at)));
                }

                $enteredAt = $decision->acted_at;
            }

            // A run still waiting is exactly what a bottleneck report exists
            // to show — the step is holding the document right now.
            if ($instance->status === 'running' && ($current = $instance->currentStep()) !== null && $enteredAt !== null) {
                $record($current->name, max(0, $enteredAt->diffInSeconds(now())));
            }
        }

        return collect($held)
            ->map(fn (array $stat, string $step) => [
                'step' => $step,
                'avg_hours' => round($stat['seconds'] / $stat['count'] / 3600, 1),
                'count' => $stat['count'],
            ])
            ->sortByDesc('avg_hours')
            ->values()
            ->all();
    }

    /**
     * Which papers travel most — external link opens per document, in this
     * period, busiest first.
     *
     * A restricted document keeps its opens (undercounting would be lying)
     * but travels under a generic label: this screen is gated on
     * papers.manage, yet its numbers get read aloud in meetings and pasted
     * into slides, and a title like "Dismissal deliberations" leaking through
     * a chart caption is precisely what the restricted marking exists to stop.
     *
     * @return array<int, array{label: string, reference: string|null, restricted: bool, opens: int}>
     */
    public function mostShared(int $limit = 5): array
    {
        $opensByShare = $this->shareAccessQuery()
            ->selectRaw('business_document_share_id, COUNT(*) as n')
            ->groupBy('business_document_share_id')
            ->pluck('n', 'business_document_share_id');

        if ($opensByShare->isEmpty()) {
            return [];
        }

        $shares = BusinessDocumentShare::query()
            ->whereIn('id', $opensByShare->keys())
            ->with('document')
            ->get();

        return $shares
            ->filter(fn (BusinessDocumentShare $share) => $share->document !== null)
            ->groupBy('business_document_id')
            ->map(function ($group) use ($opensByShare) {
                $document = $group->first()->document;

                return [
                    'label' => $document->isRestricted() ? 'Restricted document' : $document->title,
                    'reference' => $document->isRestricted() ? null : $document->reference,
                    'restricted' => $document->isRestricted(),
                    'opens' => (int) $group->sum(fn ($share) => $opensByShare[$share->id] ?? 0),
                ];
            })
            ->sortByDesc('opens')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Filed bytes per folder, heaviest first. Folder names only — never a
     * document title, so a restricted paper adds weight without a word.
     *
     * @return array<int, array{folder: string, bytes: int, files: int}>
     */
    public function storageByFolder(): array
    {
        $rows = Media::query()
            ->where('media.collection', 'document')
            ->where('media.attachable_type', (new BusinessDocument)->getMorphClass())
            ->join('business_documents', 'business_documents.id', '=', 'media.attachable_id')
            ->whereNull('business_documents.deleted_at')
            ->selectRaw('business_documents.folder_id as folder_id, COALESCE(SUM(media.size), 0) as bytes, COUNT(*) as files')
            ->groupBy('business_documents.folder_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = BusinessDocumentFolder::query()
            ->whereIn('id', $rows->pluck('folder_id')->filter())
            ->pluck('name', 'id');

        return $rows
            ->map(fn ($row) => [
                'folder' => $row->folder_id === null ? 'Unfiled' : ($names[$row->folder_id] ?? 'Deleted folder'),
                'bytes' => (int) $row->bytes,
                'files' => (int) $row->files,
            ])
            ->sortByDesc('bytes')
            ->values()
            ->all();
    }

    /** Days from creation to issue, averaged over documents issued in the period. */
    protected function averageDraftToIssueDays(): ?float
    {
        $issued = BusinessDocument::query()
            ->whereBetween('issued_at', [$this->start, $this->end])
            ->get(['created_at', 'issued_at']);

        if ($issued->isEmpty()) {
            return null;
        }

        $days = $issued->avg(fn (BusinessDocument $doc) => max(0, $doc->created_at->diffInSeconds($doc->issued_at)) / 86400);

        return round($days, 1);
    }

    /**
     * Of the signatures requested in the period, how many landed and how fast.
     *
     * @return array{signature_rate: float|null, signature_hours: float|null}
     */
    protected function signatureStats(): array
    {
        $requested = BusinessDocumentSignature::query()
            ->whereBetween('created_at', [$this->start, $this->end])
            ->get(['status', 'created_at', 'signed_at']);

        if ($requested->isEmpty()) {
            return ['signature_rate' => null, 'signature_hours' => null];
        }

        $signed = $requested->filter(fn (BusinessDocumentSignature $s) => $s->isSigned() && $s->signed_at !== null);

        return [
            'signature_rate' => round($signed->count() / $requested->count() * 100, 1),
            'signature_hours' => $signed->isEmpty()
                ? null
                : round($signed->avg(fn (BusinessDocumentSignature $s) => max(0, $s->created_at->diffInSeconds($s->signed_at)) / 3600), 1),
        ];
    }

    /**
     * Share-link opens in the window. The access log has no company column of
     * its own, so tenancy is enforced by reaching it only through the scoped
     * shares table — never queried bare.
     */
    protected function shareAccessQuery(): Builder
    {
        return BusinessDocumentShareAccess::query()
            ->whereBetween('viewed_at', [$this->start, $this->end])
            ->whereIn(
                'business_document_share_id',
                BusinessDocumentShare::query()->select('id'),
            );
    }
}
