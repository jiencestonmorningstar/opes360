<?php

namespace App\Livewire\Reports;

use App\Services\Service\SlaBoard;
use App\Support\ComplianceCalendar;
use App\Support\ContractWatch;
use App\Support\CurrentCompany;
use App\Support\Kpis;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The executive screen: the KPI set with prior-period deltas, then the alarms
 * that need eyes today.
 *
 * Every number on this page is read through the report that owns it — Kpis for
 * the cards, ComplianceCalendar, ContractWatch and SlaBoard for the alarms —
 * and every alarm links to the screen where somebody can actually act on it.
 * This page derives nothing of its own, because a summary that disagrees with
 * its detail teaches people to trust neither.
 */
class Executive extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $range = 'month';

    public function mount(): void
    {
        $this->authorize('reports.view');
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
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return view('livewire.reports.executive', ['company' => null])
                ->layout('components.layouts.app', ['title' => 'Executive', 'active' => 'reports']);
        }

        [$from, $to] = $this->window();

        $kpis = new Kpis($company, $from, $to);
        $summary = $kpis->summary();

        return view('livewire.reports.executive', [
            'company' => $company,
            'currency' => $company->currency,
            'from' => $from,
            'to' => $to,
            'kpis' => $summary,
            'customers' => $kpis->customerProfitability(),
            'projects' => $kpis->projectBudgets(),
            'alarms' => $this->alarms($summary, $kpis),
        ])->layout('components.layouts.app', ['title' => 'Executive', 'active' => 'reports']);
    }

    /**
     * The things that need eyes today, each read from its own read model and
     * each pointing at the screen where the work happens. Zero rows are kept
     * and shown as clear — an alarm panel that hides its quiet alarms leaves
     * the reader wondering whether they were checked at all.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function alarms(array $summary, Kpis $kpis): array
    {
        $compliance = (new ComplianceCalendar)->summary();
        $contracts = (new ContractWatch)->summary();
        $sla = app(SlaBoard::class)->summary();
        $stalled = $kpis->stalledApprovals();

        return [
            [
                'label' => 'Overdue receivables',
                'value' => $summary['now']['receivables_overdue'],
                'money' => true,
                'caption' => number_format($summary['now']['receivables_overdue_share'], 1).'% of what is owed',
                'route' => route('reports.aging'),
                'alarming' => $summary['now']['receivables_overdue'] > 0,
            ],
            [
                'label' => 'Compliance deadlines missed',
                'value' => $compliance['overdue'],
                'money' => false,
                'caption' => $compliance['due_soon'].' more due soon',
                'route' => route('compliance'),
                'alarming' => $compliance['overdue'] > 0,
            ],
            [
                'label' => 'Contracts about to auto-renew',
                'value' => $contracts['auto_renewing_at_risk'],
                'money' => false,
                'caption' => $contracts['notice_lapsing'].' notice deadlines approaching',
                'route' => route('contracts.index'),
                'alarming' => $contracts['auto_renewing_at_risk'] > 0,
            ],
            [
                'label' => 'SLA breaches',
                'value' => $sla['response_breached'] + $sla['resolution_breached'],
                'money' => false,
                'caption' => $sla['open'].' tickets on the clock',
                'route' => route('service'),
                'alarming' => ($sla['response_breached'] + $sla['resolution_breached']) > 0,
            ],
            [
                'label' => 'Stalled approvals',
                'value' => $stalled,
                'money' => false,
                'caption' => 'Waiting with no path forward',
                'route' => route('actions'),
                'alarming' => $stalled > 0,
            ],
        ];
    }
}
