<?php

namespace App\Livewire\Reports;

use App\Support\Aging as AgingReport;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Who owes us, and who we owe, sorted by how late.
 *
 * Two sides on one screen rather than two screens, because the question a
 * business actually has at month end is about the gap between them: being owed
 * four million matters differently when you owe three.
 */
class Aging extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $side = 'receivable';

    public ?string $openParty = null;

    /*
     * There is deliberately no "as of" date control.
     *
     * Backdating this report properly needs the balance each document had on
     * that date, and the platform stores only the balance it has now. A control
     * that rewound the age but not the balances would produce a report that
     * looks authoritative and is wrong twice over: invoices raised after the
     * chosen date would appear, and invoices that were outstanding then but
     * have since been paid would not.
     *
     * The service still accepts an as-of date, because the age arithmetic has
     * to be testable against a fixed today. Exposing it here would be selling
     * something we cannot yet honour.
     */

    public function mount(): void
    {
        $this->authorize('reports.view');
    }

    public function setSide(string $side): void
    {
        $this->side = in_array($side, ['receivable', 'payable'], true) ? $side : 'receivable';
        $this->openParty = null;
    }

    public function toggleParty(string $id): void
    {
        $this->openParty = $this->openParty === $id ? null : $id;
    }

    protected function report(): array
    {
        $aging = new AgingReport;

        return $this->side === 'payable' ? $aging->payable() : $aging->receivable();
    }

    /**
     * A CSV of the detail, not the summary.
     *
     * The summary is what the screen already shows; what somebody downloads an
     * aging report for is to work through it line by line, or hand it to their
     * accountant, and a grid of bucket totals cannot be worked through.
     */
    public function export(): mixed
    {
        $this->authorize('reports.export');

        $report = $this->report();
        $side = $this->side === 'payable' ? 'payables' : 'receivables';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Party', 'Document', 'Issued', 'Due', 'Days overdue', 'Bucket', 'Amount']);

            foreach ($report['rows'] as $row) {
                foreach ($row['items'] as $item) {
                    fputcsv($out, [
                        $row['party'],
                        $item['number'],
                        $item['issue_date']?->toDateString(),
                        $item['due_date']?->toDateString(),
                        $item['days_overdue'],
                        AgingReport::BUCKETS[$item['bucket']],
                        $item['amount'],
                    ]);
                }
            }

            fclose($out);
        }, "aging-{$side}-".now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        $report = $this->report();

        return view('livewire.reports.aging', [
            'report' => $report,
            'buckets' => AgingReport::BUCKETS,
            'currency' => auth()->user()->currentCompany?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['active' => 'reports', 'title' => 'Aging']);
    }
}
