<?php

namespace App\Livewire;

use App\Enums\DocumentType;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Receipt;
use App\Support\CurrentCompany;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class Dashboard extends Component
{
    /** Window for the headline stat cards: today | week | month. */
    #[Url(as: 'range')]
    public string $range = 'today';

    /** Window for the sales chart, independent of the stat cards. */
    public string $chartRange = 'week';

    /** Window for the top-customers panel, independent of both. */
    public string $customerRange = 'month';

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['today', 'week', 'month'], true) ? $range : 'today';
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        // Without a company there is no tenant data to read; the view renders its
        // empty state rather than the global scope silently returning nothing.
        if ($company === null) {
            return view('livewire.dashboard', ['company' => null])
                ->layout('components.layouts.app', ['title' => 'Dashboard', 'active' => 'home']);
        }

        $currency = $company->currency;
        $now = CarbonImmutable::now($company->timezone);

        [$from, $to] = $this->window($now);
        [$prevFrom, $prevTo] = $this->previousWindow($now);

        $current = $this->metrics($from, $to, $currency);
        $previous = $this->metrics($prevFrom, $prevTo, $currency);

        return view('livewire.dashboard', [
            'company' => $company,
            'currency' => $currency,
            'greeting' => $this->greeting($now),
            'rangeLabel' => $this->rangeLabel(),
            'stats' => $this->stats($current, $previous, $currency),
            'chart' => $this->chart($now, $currency),
            'chartTotal' => Money::format($this->chart($now, $currency)->sum('value'), $currency),
            'topCustomers' => $this->topCustomers($now, $currency),
            'recentInvoices' => $this->recentInvoices(),
            'counters' => $this->counters($now, $currency),
        ])->layout('components.layouts.app', ['title' => 'Dashboard', 'active' => 'home']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function window(CarbonImmutable $now): array
    {
        return match ($this->range) {
            'week' => [$now->startOfWeek(), $now->endOfWeek()],
            'month' => [$now->startOfMonth(), $now->endOfMonth()],
            default => [$now->startOfDay(), $now->endOfDay()],
        };
    }

    /** The equivalent window immediately before the current one, for the deltas. */
    protected function previousWindow(CarbonImmutable $now): array
    {
        return match ($this->range) {
            'week' => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            default => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
        };
    }

    protected function rangeLabel(): string
    {
        return match ($this->range) {
            'week' => 'This Week',
            'month' => 'This Month',
            default => 'Today',
        };
    }

    protected function deltaCaption(): string
    {
        return match ($this->range) {
            'week' => 'vs last week',
            'month' => 'vs last month',
            default => 'vs yesterday',
        };
    }

    /**
     * @return array{sales: float, paid: float, invoices: int}
     */
    /**
     * Every sum here is scoped to the company's own currency: a document or
     * payment recorded in a different currency (a foreign-currency invoice,
     * say) is real money but not a number that can be added to a USD total
     * without a conversion this dashboard doesn't do — mixing them in would
     * silently inflate the figures rather than report them correctly.
     */
    protected function metrics(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        $invoices = Document::query()->invoices()->issuedBetween($from, $to)->where('currency', $currency);

        return [
            'sales' => (float) (clone $invoices)->sum('total'),
            'invoices' => (int) (clone $invoices)->count(),
            'paid' => (float) Payment::query()
                ->whereBetween('received_at', [$from, $to])
                ->where('currency', $currency)
                ->sum('amount'),
        ];
    }

    protected function stats(array $current, array $previous, string $currency): array
    {
        // Outstanding is a running balance, not a windowed figure — it answers
        // "what am I owed right now", which never resets with the date filter.
        $outstanding = (float) Document::query()->invoices()->outstanding()->where('currency', $currency)->sum('balance');

        $caption = $this->deltaCaption();

        return [
            [
                'label' => 'Total Sales',
                'value' => Money::format($current['sales'], $currency),
                'icon' => 'trending-up',
                'accent' => 'blue',
                'delta' => $this->formatDelta(Money::change($current['sales'], $previous['sales'])),
                'deltaTone' => 'positive',
                'deltaCaption' => $caption,
            ],
            [
                'label' => 'Paid',
                'value' => Money::format($current['paid'], $currency),
                'icon' => 'wallet',
                'accent' => 'green',
                'delta' => $this->formatDelta(Money::change($current['paid'], $previous['paid'])),
                'deltaTone' => 'positive',
                'deltaCaption' => $caption,
            ],
            [
                'label' => 'Outstanding',
                'value' => Money::format($outstanding, $currency),
                'icon' => 'clock',
                'accent' => 'orange',
                'delta' => $this->formatDelta(Money::change(
                    $current['sales'] - $current['paid'],
                    $previous['sales'] - $previous['paid'],
                )),
                'deltaTone' => 'warning',
                'deltaCaption' => $caption,
            ],
            [
                'label' => 'Total Invoices',
                'value' => (string) $current['invoices'],
                'icon' => 'document',
                'accent' => 'purple',
                'delta' => $this->formatCountDelta($current['invoices'] - $previous['invoices']),
                'deltaTone' => 'positive',
                'deltaCaption' => $caption,
            ],
        ];
    }

    protected function formatDelta(?float $change): ?string
    {
        return $change === null ? null : rtrim(rtrim(number_format($change, 1), '0'), '.').'%';
    }

    protected function formatCountDelta(int $difference): ?string
    {
        return $difference === 0 ? null : (string) abs($difference);
    }

    /** Seven days of issued invoice value, Monday through Sunday. */
    protected function chart(CarbonImmutable $now, string $currency): Collection
    {
        $start = $now->startOfWeek();

        $totals = Document::query()
            ->invoices()
            ->issuedBetween($start, $start->addDays(6))
            ->where('currency', $currency)
            ->get(['issue_date', 'total'])
            ->groupBy(fn (Document $d) => $d->issue_date->toDateString())
            ->map(fn (Collection $group) => (float) $group->sum('total'));

        $series = collect(range(0, 6))->map(function (int $offset) use ($start, $totals) {
            $day = $start->addDays($offset);

            return [
                'label' => $day->format('D'),
                'value' => $totals->get($day->toDateString(), 0.0),
            ];
        });

        // The design highlights the strongest day of the week in solid brand blue.
        $peak = $series->max('value');

        return $series->map(fn (array $point) => [
            ...$point,
            'highlight' => $peak > 0 && $point['value'] === $peak,
        ]);
    }

    protected function topCustomers(CarbonImmutable $now, string $currency): Collection
    {
        $totals = Document::query()
            ->invoices()
            ->issuedBetween($now->startOfMonth(), $now->endOfMonth())
            ->where('currency', $currency)
            ->selectRaw('contact_id, SUM(total) as revenue')
            ->whereNotNull('contact_id')
            ->groupBy('contact_id')
            ->orderByDesc('revenue')
            ->limit(4)
            ->pluck('revenue', 'contact_id');

        if ($totals->isEmpty()) {
            return collect();
        }

        return Contact::query()
            ->whereIn('id', $totals->keys())
            ->get()
            ->sortByDesc(fn (Contact $c) => (float) $totals[$c->id])
            ->values()
            ->map(fn (Contact $contact) => [
                'contact' => $contact,
                'revenue' => (float) $totals[$contact->id],
            ]);
    }

    protected function recentInvoices(): Collection
    {
        return Document::query()
            ->ofType(DocumentType::Invoice)
            ->issued()
            // A document dated in the future is scheduled, not recent — listing it
            // here would bury today's activity under next week's paperwork.
            ->where('issue_date', '<=', CarbonImmutable::now()->endOfDay())
            ->with('contact')
            ->latest('issue_date')
            ->latest('created_at')
            ->orderByDesc('number')
            ->limit(3)
            ->get();
    }

    protected function counters(CarbonImmutable $now, string $currency): array
    {
        $newCustomers = Contact::query()
            ->customers()
            ->where('created_at', '>=', $now->startOfMonth())
            ->count();

        $lowStock = Item::query()->products()->active()->where('track_stock', true)->get()
            ->filter(fn (Item $item) => $item->isLowStock())
            ->count();

        // Expenses land in Phase 7 alongside supplier payments; until then this
        // reads supplier-side payments so the tile shows real data, not a stub.
        $expenses = (float) Payment::query()
            ->whereHas('contact', fn ($q) => $q->whereIn('type', ['supplier', 'vendor']))
            ->whereBetween('received_at', [$now->startOfMonth(), $now->endOfMonth()])
            ->sum('amount');

        return [
            [
                'label' => 'Customers',
                'value' => (string) Contact::query()->customers()->count(),
                'icon' => 'users',
                'accent' => 'blue',
                'caption' => $newCustomers > 0 ? $newCustomers.' this month' : 'No new this month',
                'captionTone' => $newCustomers > 0 ? 'positive' : 'muted',
                'captionArrow' => $newCustomers > 0,
            ],
            [
                'label' => 'Products',
                'value' => (string) Item::query()->products()->active()->count(),
                'icon' => 'cube',
                'accent' => 'green',
                'caption' => $lowStock > 0 ? $lowStock.' low on stock' : 'In stock',
                'captionTone' => $lowStock > 0 ? 'warning' : 'positive',
                'captionArrow' => false,
            ],
            [
                'label' => 'Receipts',
                'value' => (string) Receipt::query()
                    ->whereBetween('issued_at', [$now->startOfDay(), $now->endOfDay()])
                    ->count(),
                'icon' => 'receipt',
                'accent' => 'purple',
                'caption' => 'Today',
                'captionTone' => 'brand',
                'captionArrow' => false,
            ],
            [
                'label' => 'Expenses',
                'value' => Money::format($expenses, $currency),
                'icon' => 'alert',
                'accent' => 'orange',
                'caption' => 'This month',
                'captionTone' => 'muted',
                'captionArrow' => false,
            ],
        ];
    }

    protected function greeting(CarbonImmutable $now): string
    {
        return match (true) {
            $now->hour < 12 => 'Good morning',
            $now->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
