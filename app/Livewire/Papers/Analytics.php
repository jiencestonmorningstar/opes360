<?php

namespace App\Livewire\Papers;

use App\Support\CurrentCompany;
use App\Support\DocumentAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Documents plan phase 2.19 — the documents analytics screen.
 *
 * Every figure comes through DocumentAnalytics, which reads each number from
 * the table that owns it, on the Executive screen's principle: a dashboard
 * that derives its own figures ends up disagreeing with the detail a click
 * away, and then nobody trusts either.
 *
 * Gated on papers.manage, not papers.view, and the choice needs arguing: this
 * page aggregates across EVERY document in the company, including restricted
 * ones' metadata — their counts, their share-open tallies, their filed bytes.
 * A holder of papers.view sees lists that readableBy() has already narrowed;
 * handing that person totals computed over the unnarrowed table would let
 * them learn things the lists refuse them (that restricted papers exist, how
 * many, how often they travel). papers.manage is exactly the ability that
 * already opens restricted documents, so it is the one gate under which these
 * aggregates reveal nothing their holder could not read directly. Even so,
 * restricted titles never appear here — only counts — because a number on
 * this screen gets pasted into a slide, and the audience of the slide is not
 * the audience of the gate.
 */
class Analytics extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $range = 'month';

    public function mount(): void
    {
        $this->authorize('papers.manage');
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['week', 'month', 'quarter', 'year'], true) ? $range : 'month';
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function window(): array
    {
        $now = CarbonImmutable::now(app(CurrentCompany::class)->get()?->timezone ?? 'UTC');

        return match ($this->range) {
            'week' => [$now->startOfWeek(), $now->endOfWeek()],
            'quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'year' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
    }

    public function render(): View
    {
        [$from, $to] = $this->window();

        $analytics = new DocumentAnalytics($from, $to);

        return view('livewire.papers.analytics', [
            'from' => $from,
            'to' => $to,
            'summary' => $analytics->summary(),
            'byKind' => $analytics->byKind(),
            'bottlenecks' => $analytics->bottlenecks(),
            'mostShared' => $analytics->mostShared(),
            'storage' => $analytics->storageByFolder(),
        ])->layout('components.layouts.app', [
            'title' => 'Document analytics',
            'active' => 'papers',
        ]);
    }
}
