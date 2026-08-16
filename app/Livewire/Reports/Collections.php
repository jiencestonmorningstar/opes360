<?php

namespace App\Livewire\Reports;

use App\Models\CollectionActivity;
use App\Models\Contact;
use App\Support\CollectionsQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The collections workspace: who to chase, in order, and what was said last time.
 *
 * Logging lives on the same screen as the queue on purpose. A collector who has
 * to leave the list, find the customer, and open a notes tab to record a call
 * simply does not record it — and an unlogged call is worse than no call,
 * because the next person rings the same customer about the same invoice.
 */
class Collections extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $filter = 'all';

    public ?string $openParty = null;

    // ── the logging form ──
    public ?string $logFor = null;

    public string $kind = CollectionActivity::KIND_CALL;

    public string $body = '';

    public ?string $promisedAt = null;

    public ?string $promisedAmount = null;

    public function mount(): void
    {
        $this->authorize('reports.view');
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'broken', 'untouched', 'over_90'], true) ? $filter : 'all';
    }

    public function toggleParty(string $id): void
    {
        $this->openParty = $this->openParty === $id ? null : $id;
    }

    public function openLog(string $contactId): void
    {
        $this->logFor = $contactId;
        $this->kind = CollectionActivity::KIND_CALL;
        $this->body = '';
        $this->promisedAt = null;
        $this->promisedAmount = null;
        $this->resetErrorBag();
    }

    public function closeLog(): void
    {
        $this->logFor = null;
        $this->resetErrorBag();
    }

    public function saveLog(): void
    {
        $this->authorize('reports.view');

        $this->resetErrorBag();

        if ($this->logFor === null) {
            return;
        }

        if (trim($this->body) === '') {
            $this->addError('body', 'Say what happened — an empty note tells the next person nothing.');
        }

        /*
         * A promise without a date is not a promise. It is the difference
         * between "they will pay" and "they said the 14th", and only the second
         * can be broken — which is what the queue sorts on.
         */
        if ($this->kind === CollectionActivity::KIND_PROMISE && blank($this->promisedAt)) {
            $this->addError('promisedAt', 'A promise to pay needs the date they promised.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $contact = Contact::query()->findOrFail($this->logFor);

        CollectionActivity::create([
            'company_id' => $contact->company_id,
            'contact_id' => $contact->id,
            'user_id' => auth()->id(),
            'kind' => $this->kind,
            'body' => trim($this->body),
            'promised_at' => $this->kind === CollectionActivity::KIND_PROMISE
                ? Carbon::parse($this->promisedAt)->toDateString()
                : null,
            'promised_amount' => $this->kind === CollectionActivity::KIND_PROMISE && filled($this->promisedAmount)
                ? round((float) $this->promisedAmount, 2)
                : null,
            'happened_at' => now(),
        ]);

        $this->closeLog();
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(): Collection
    {
        $rows = (new CollectionsQueue)->accounts();

        return match ($this->filter) {
            'broken' => $rows->where('flag', CollectionsQueue::FLAG_BROKEN_PROMISE)->values(),
            'untouched' => $rows->whereNull('last_activity')->values(),
            'over_90' => $rows->filter(fn (array $r) => $r['oldest_days'] > 90)->values(),
            default => $rows,
        };
    }

    public function export(): mixed
    {
        $this->authorize('reports.export');

        $rows = $this->rows();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Customer', 'Email', 'Phone', 'Overdue', 'Credit held', 'Net exposure',
                'Oldest days', 'Priority', 'Status', 'Promised', 'Last reminder', 'Last contact',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['party'],
                    $row['email'],
                    $row['phone'],
                    $row['overdue_total'],
                    $row['credit_available'],
                    $row['net_exposure'],
                    $row['oldest_days'],
                    $row['priority'],
                    $row['flag'] ?? 'open',
                    $row['promised_at']?->toDateString(),
                    $row['last_reminder_at']?->toDateString(),
                    $row['last_activity']?->happened_at?->toDateString(),
                ]);
            }

            fclose($out);
        }, 'collections-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        $rows = $this->rows();

        return view('livewire.reports.collections', [
            'rows' => $rows,
            'summary' => (new CollectionsQueue)->summary($rows),
            'kinds' => CollectionActivity::KINDS,
            'currency' => auth()->user()->currentCompany?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['active' => 'reports', 'title' => 'Collections']);
    }
}
