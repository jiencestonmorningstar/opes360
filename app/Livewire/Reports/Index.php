<?php

namespace App\Livewire\Reports;

use App\Enums\PaymentMethod;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Payment;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Index extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $range = 'month';

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

    public function exportCsv(): StreamedResponse
    {
        // Reading a report on screen and walking out with the whole ledger as
        // a file are different rights, and the catalogue has always said so —
        // Sales Officer and Read Only are granted reports.view but not
        // reports.export. Until this check existed the distinction was
        // decorative: the route only gates the page.
        $this->authorize('reports.export');

        [$from, $to] = $this->window();

        $rows = Document::query()
            ->invoices()
            ->issuedBetween($from, $to)
            ->with('contact')
            ->orderBy('issue_date')
            ->get();

        $filename = 'sales-'.$this->range.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Number', 'Date', 'Customer', 'Status', 'Currency', 'Total', 'Paid', 'Balance']);

            foreach ($rows as $document) {
                fputcsv($out, [
                    $document->number,
                    $document->issue_date?->toDateString(),
                    $document->contact?->displayName(),
                    $document->paymentState()['label'],
                    $document->currency,
                    $document->total,
                    $document->amount_paid,
                    $document->balance,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        [$from, $to] = $this->window();
        $currency = app(CurrentCompany::class)->get()?->currency ?? 'USD';

        $invoices = Document::query()->invoices()->issuedBetween($from, $to);

        $revenue = (float) (clone $invoices)->sum('total');
        $count = (int) (clone $invoices)->count();
        $collected = (float) Payment::query()->whereBetween('received_at', [$from, $to])->sum('amount');

        return view('livewire.reports.index', [
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'metrics' => [
                'revenue' => $revenue,
                'collected' => $collected,
                'outstanding' => (float) Document::query()->invoices()->outstanding()->sum('balance'),
                'count' => $count,
                'average' => $count > 0 ? $revenue / $count : 0.0,
            ],
            'series' => $this->series($from, $to),
            'methodBreakdown' => $this->methodBreakdown($from, $to, $collected),
            'topCustomers' => $this->topCustomers($from, $to),
        ])->layout('components.layouts.app', ['title' => 'Reports', 'active' => 'reports']);
    }

    /** Buckets sized to the range so the chart stays readable at every zoom. */
    protected function series(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $documents = Document::query()
            ->invoices()
            ->issuedBetween($from, $to)
            ->get(['issue_date', 'total']);

        if (in_array($this->range, ['week'], true)) {
            $buckets = collect(range(0, 6))->map(fn (int $i) => [
                'label' => $from->addDays($i)->format('D'),
                'key' => $from->addDays($i)->toDateString(),
            ]);

            $grouped = $documents->groupBy(fn ($d) => $d->issue_date->toDateString());
        } elseif ($this->range === 'year') {
            $buckets = collect(range(0, 11))->map(fn (int $i) => [
                'label' => $from->addMonths($i)->format('M'),
                'key' => $from->addMonths($i)->format('Y-m'),
            ]);

            $grouped = $documents->groupBy(fn ($d) => $d->issue_date->format('Y-m'));
        } else {
            // Month and quarter bucket by week-of-range.
            $weeks = (int) ceil($from->diffInDays($to) / 7);
            $buckets = collect(range(0, max(0, $weeks - 1)))->map(fn (int $i) => [
                'label' => 'W'.($i + 1),
                'key' => (string) $i,
            ]);

            $grouped = $documents->groupBy(
                fn ($d) => (string) intdiv($from->diffInDays($d->issue_date), 7)
            );
        }

        $series = $buckets->map(fn (array $bucket) => [
            'label' => $bucket['label'],
            'value' => (float) ($grouped->get($bucket['key'])?->sum('total') ?? 0),
        ]);

        $peak = $series->max('value');

        return $series
            ->map(fn (array $p) => [...$p, 'highlight' => $peak > 0 && $p['value'] === $peak])
            ->all();
    }

    protected function methodBreakdown(CarbonImmutable $from, CarbonImmutable $to, float $total): array
    {
        $sums = Payment::query()
            ->whereBetween('received_at', [$from, $to])
            ->selectRaw('method, SUM(amount) as method_total')
            ->groupBy('method')
            ->pluck('method_total', 'method');

        return collect(PaymentMethod::cases())
            ->map(fn (PaymentMethod $method) => [
                'label' => $method->label(),
                'amount' => (float) ($sums[$method->value] ?? 0),
                'share' => $total > 0 ? round((float) ($sums[$method->value] ?? 0) / $total * 100) : 0,
            ])
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    protected function topCustomers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = Document::query()
            ->invoices()
            ->issuedBetween($from, $to)
            ->whereNotNull('contact_id')
            ->selectRaw('contact_id, SUM(total) as revenue')
            ->groupBy('contact_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->pluck('revenue', 'contact_id');

        if ($totals->isEmpty()) {
            return [];
        }

        return Contact::query()
            ->whereIn('id', $totals->keys())
            ->get()
            ->sortByDesc(fn (Contact $c) => (float) $totals[$c->id])
            ->values()
            ->map(fn (Contact $contact) => [
                'contact' => $contact,
                'revenue' => (float) $totals[$contact->id],
            ])
            ->all();
    }
}
