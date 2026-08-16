<?php

namespace App\Livewire\Reports;

use App\Models\Contact;
use App\Support\Statement as StatementReport;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A statement of account for one customer over one period.
 *
 * The period defaults to the last three months rather than the current one. A
 * statement is usually produced because somebody disagrees about a balance, and
 * the invoice in dispute is almost never the one raised this month.
 */
class Statement extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'contact')]
    public ?string $contactId = null;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('reports.view');

        $this->from = $this->from ?: now()->subMonths(3)->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    /** @return array<int, Contact> */
    public function customers(): array
    {
        return Contact::query()
            ->whereHas('documents')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->all();
    }

    public function contact(): ?Contact
    {
        return $this->contactId === null ? null : Contact::query()->find($this->contactId);
    }

    /**
     * A period that runs backwards produces an empty statement with a plausible
     * opening balance, which is worse than an error: it looks like an answer.
     */
    protected function validatePeriod(): bool
    {
        $this->resetErrorBag();

        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->addError('to', 'The end of the period comes before its start.');

            return false;
        }

        return true;
    }

    protected function report(): ?array
    {
        $contact = $this->contact();

        if ($contact === null || ! $this->validatePeriod()) {
            return null;
        }

        return (new StatementReport($contact, Carbon::parse($this->from), Carbon::parse($this->to)))->build();
    }

    public function export(): mixed
    {
        $this->authorize('reports.export');

        $report = $this->report();

        if ($report === null) {
            return null;
        }

        $name = 'statement-'.str($report['contact']->displayName())->slug().'-'.$this->to.'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Balance']);
            fputcsv($out, [$report['from']->toDateString(), '', '', 'Opening balance', '', '', $report['opening_balance']]);

            foreach ($report['lines'] as $line) {
                fputcsv($out, [
                    Carbon::parse($line['date'])->toDateString(),
                    $line['kind'],
                    $line['reference'],
                    $line['description'],
                    $line['debit'] ?: '',
                    $line['credit'] ?: '',
                    $line['balance'],
                ]);
            }

            fputcsv($out, [$report['to']->toDateString(), '', '', 'Closing balance', '', '', $report['closing_balance']]);

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        return view('livewire.reports.statement', [
            'report' => $this->report(),
            'customers' => $this->customers(),
            'currency' => auth()->user()->currentCompany?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['active' => 'reports', 'title' => 'Statement']);
    }
}
