<?php

namespace App\Livewire\Accounting;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
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
        'chart' => 'Plan comptable',
    ];

    // ---- chart management ----

    public string $newNumber = '';

    public string $newName = '';

    /** '' = derive from the parent account or the class. */
    public string $newSide = '';

    public string $editingId = '';

    public string $editingName = '';

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

    /**
     * Adds an account to the chart.
     *
     * The side defaults to what the number implies — a subdivision inherits
     * its parent's side, anything else follows its class — but the accountant
     * can override it, because class 4 genuinely cannot be derived: 4191
     * avances reçues sits one digit away from 411 Clients and faces the
     * other way.
     */
    public function addAccount(): void
    {
        $this->authorize('accounting.manage');

        $company = app(CurrentCompany::class)->get();

        $this->validate([
            // 2-8 digits, leading digit naming a real SYSCOHADA class.
            'newNumber' => ['required', 'regex:/^[1-8][0-9]{1,7}$/'],
            'newName' => ['required', 'string', 'max:120'],
            'newSide' => ['nullable', 'in:debit,credit'],
        ], [
            'newNumber.regex' => 'An account number is 2–8 digits and starts with its class (1–8).',
        ]);

        $exists = LedgerAccount::query()
            ->where('number', $this->newNumber)
            ->exists();

        if ($exists) {
            $this->addError('newNumber', 'That account number is already in the chart.');

            return;
        }

        LedgerAccount::create([
            'number' => $this->newNumber,
            'name' => trim($this->newName),
            'class' => LedgerAccount::classOf($this->newNumber),
            'normal_balance' => $this->newSide ?: ChartOfAccounts::suggestSide($company, $this->newNumber),
        ]);

        $this->reset('newNumber', 'newName', 'newSide');
        session()->flash('accountingStatus', 'Account added to the chart.');
    }

    /** One-click add for a subdivision the plan itself lists. */
    public function addSuggested(string $number): void
    {
        $this->authorize('accounting.manage');

        $company = app(CurrentCompany::class)->get();

        foreach (ChartOfAccounts::SUB_ACCOUNTS as $subs) {
            if (! array_key_exists($number, $subs)) {
                continue;
            }

            if (LedgerAccount::query()->where('number', $number)->exists()) {
                return;
            }

            LedgerAccount::create([
                'number' => $number,
                'name' => $subs[$number],
                'class' => LedgerAccount::classOf($number),
                'normal_balance' => ChartOfAccounts::suggestSide($company, $number),
            ]);

            session()->flash('accountingStatus', "Added {$number} — {$subs[$number]}.");

            return;
        }
    }

    public function startRename(string $id): void
    {
        $this->authorize('accounting.manage');

        $account = LedgerAccount::query()->findOrFail($id);

        $this->editingId = $account->id;
        $this->editingName = $account->name;
        $this->resetErrorBag();
    }

    public function saveRename(): void
    {
        $this->authorize('accounting.manage');

        $this->validate(['editingName' => ['required', 'string', 'max:120']]);

        LedgerAccount::query()->findOrFail($this->editingId)
            ->update(['name' => trim($this->editingName)]);

        $this->reset('editingId', 'editingName');
        session()->flash('accountingStatus', 'Account renamed.');
    }

    public function cancelRename(): void
    {
        $this->reset('editingId', 'editingName');
    }

    public function toggleActive(string $id): void
    {
        $this->authorize('accounting.manage');

        $account = LedgerAccount::query()->findOrFail($id);
        $account->update(['is_active' => ! $account->is_active]);
    }

    /**
     * Removes an account — but only one the books have never touched. An
     * account with even one journal line is part of the record and stays;
     * deactivating is the tool for "we stopped using this".
     */
    public function deleteAccount(string $id): void
    {
        $this->authorize('accounting.manage');

        $account = LedgerAccount::query()->findOrFail($id);

        if ($account->lines()->exists()) {
            session()->flash('accountingStatus', 'That account has entries and cannot be deleted — deactivate it instead.');

            return;
        }

        $account->delete();
        session()->flash('accountingStatus', "Account {$account->number} removed.");
    }

    public function render(Books $books): View
    {
        $company = app(CurrentCompany::class)->get();

        $accounts = $books->accounts($company);

        // The grand livre needs an account; the first one with movement is a
        // better landing place than an empty table.
        $selected = $accounts->firstWhere('id', $this->account) ?? $accounts->first();

        $data = match ($this->tab) {
            'chart' => [
                'chartRows' => $accounts->map(function ($account) {
                    $debit = (float) $account->lines()->sum('debit');
                    $credit = (float) $account->lines()->sum('credit');

                    return [
                        'account' => $account,
                        'hasMovement' => $debit != 0.0 || $credit != 0.0,
                        'balance' => $account->isDebitNormal() ? $debit - $credit : $credit - $debit,
                    ];
                }),
                // Union, not flatMap: the account numbers are numeric array
                // keys, and merge-style flattening renumbers those from zero —
                // which turned every chip into addSuggested('0') and made the
                // click a silent no-op.
                'suggested' => collect(array_reduce(
                    ChartOfAccounts::SUB_ACCOUNTS,
                    fn ($carry, $subs) => $carry + $subs,
                    [],
                ))->reject(fn ($name, $number) => $accounts->contains('number', (string) $number)),
            ],
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
