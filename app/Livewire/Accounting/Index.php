<?php

namespace App\Livewire\Accounting;

use App\Models\JournalEntry;
use App\Services\Accounting\Books;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The books, read five ways: balance, grand livre, journal, résultat, bilan.
 *
 * One component rather than five pages because they are one thing viewed from
 * different angles, and every one of them needs the same period picker — a
 * date range that lives in five places is a date range that disagrees with
 * itself.
 */
class Index extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $tab = 'balance';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** Which account the grand livre is showing. */
    #[Url]
    public string $account = '';

    #[Url]
    public string $journal = '';

    public const TABS = [
        'balance' => 'Balance générale',
        'ledger' => 'Grand livre',
        'journal' => 'Journal',
        'income' => 'Compte de résultat',
        'sheet' => 'Bilan',
    ];

    public function mount(): void
    {
        $this->authorize('accounting.view');

        // Defaults to the financial year to date, which is what anyone opening
        // the books without a period in mind wants to see.
        if ($this->from === '') {
            $this->from = CarbonImmutable::now()->startOfYear()->toDateString();
        }

        if ($this->to === '') {
            $this->to = CarbonImmutable::now()->toDateString();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'balance';
    }

    /** The trial balance as a file, for handing to an accountant. */
    public function exportBalance(Books $books): StreamedResponse
    {
        $this->authorize('accounting.export');

        $company = app(CurrentCompany::class)->get();
        $balance = $books->trialBalance($company, $this->from, $this->to);

        return response()->streamDownload(function () use ($balance) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Compte', 'Libellé', 'Débit', 'Crédit', 'Solde']);

            foreach ($balance['rows'] as $row) {
                fputcsv($out, [
                    $row['account']->number,
                    $row['account']->name,
                    $row['debit'],
                    $row['credit'],
                    $row['balance'],
                ]);
            }

            fputcsv($out, ['', 'TOTAUX', $balance['total_debit'], $balance['total_credit'], '']);
            fclose($out);
        }, 'balance-'.$this->from.'-'.$this->to.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function seedChart(): void
    {
        $this->authorize('accounting.view');

        $company = app(CurrentCompany::class)->get();
        $created = ChartOfAccounts::seed($company);

        session()->flash('accountingStatus', $created > 0
            ? "Added {$created} account".($created === 1 ? '' : 's').' to the chart.'
            : 'The chart already has every starter account.');
    }

    public function render(Books $books): View
    {
        $company = app(CurrentCompany::class)->get();

        $accounts = $books->accounts($company);

        // The grand livre needs an account; the first one with movement is a
        // better landing place than an empty table.
        $selected = $accounts->firstWhere('id', $this->account) ?? $accounts->first();

        $data = match ($this->tab) {
            'ledger' => ['ledger' => $selected
                ? $books->accountLedger($company, $selected, $this->from, $this->to)
                : null],
            'journal' => ['entries' => $books->journal($company, $this->journal ?: null, $this->from, $this->to)],
            'income' => ['income' => $books->incomeStatement($company, $this->from, $this->to)],
            'sheet' => ['sheet' => $books->balanceSheet($company, $this->from, $this->to)],
            default => ['balance' => $books->trialBalance($company, $this->from, $this->to)],
        };

        return view('livewire.accounting.index', array_merge($data, [
            'company' => $company,
            'accounts' => $accounts,
            'selectedAccount' => $selected,
            'tabs' => self::TABS,
            'journals' => JournalEntry::JOURNALS,
        ]))->layout('components.layouts.app', [
            'title' => 'Accounting',
            'active' => 'accounting',
        ]);
    }
}
