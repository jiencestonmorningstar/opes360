<?php

namespace App\Livewire\Banking;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\Banking\Reconciler;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Matching the statement against the books.
 *
 * The screen shows the arithmetic rather than a verdict: book balance,
 * statement balance, and the unmatched movements that explain the gap. A green
 * tick over three unexplained lines is not a reconciliation, it is a place
 * nobody will look again.
 */
class Index extends Component
{
    use WithFileUploads;

    #[Url]
    public ?string $accountId = null;

    #[Url]
    public string $filter = 'unmatched'; // unmatched|matched|ignored|all

    public bool $addingAccount = false;

    public bool $importing = false;

    public ?string $matching = null;

    // ── New bank account ────────────────────────────────────────────────
    public string $accountName = '';

    public string $bankName = '';

    public string $accountNumber = '';

    public string $ledgerAccountId = '';

    // ── Statement ───────────────────────────────────────────────────────
    public $statementFile;

    public string $statementBalance = '';

    public string $statementDate = '';

    public string $openingBalance = '0';

    public string $openedOn = '';

    // ── Adding a line by hand ───────────────────────────────────────────
    public bool $addingLine = false;

    public string $lineDate = '';

    public string $lineDescription = '';

    public string $lineAmount = '';

    public string $lineReference = '';

    // ── Recording an unmatched line into the books ──────────────────────
    public ?string $recording = null;

    public string $counterAccount = '';

    public function mount(): void
    {
        Gate::authorize('banking.view');

        $this->statementDate = now()->toDateString();
        $this->lineDate = now()->toDateString();

        $this->accountId ??= BankAccount::query()
            ->where('active', true)
            ->orderByDesc('is_default')
            ->value('id');
    }

    public function account(): ?BankAccount
    {
        return $this->accountId === null
            ? null
            : BankAccount::query()->with('account')->find($this->accountId);
    }

    // ── Accounts ────────────────────────────────────────────────────────

    public function startAddingAccount(): void
    {
        Gate::authorize('banking.manage');

        $this->reset(['accountName', 'bankName', 'accountNumber', 'ledgerAccountId']);
        $this->resetValidation();
        $this->addingAccount = true;
    }

    public function saveAccount(): void
    {
        Gate::authorize('banking.manage');

        $this->validate([
            'accountName' => ['required', 'string', 'max:120'],
            'bankName' => ['nullable', 'string', 'max:120'],
            'accountNumber' => ['nullable', 'string', 'max:60'],
            'ledgerAccountId' => ['required', 'string'],
        ], [
            'ledgerAccountId.required' => 'Pick the ledger account this mirrors — without it there is nothing to reconcile against.',
        ]);

        $company = app(CurrentCompany::class)->get();

        $account = BankAccount::create([
            'company_id' => $company->id,
            'name' => trim($this->accountName),
            'bank_name' => $this->bankName ?: null,
            'account_number' => $this->accountNumber ?: null,
            'currency' => $company->currency ?: 'XAF',
            'ledger_account_id' => $this->ledgerAccountId,
            'is_default' => BankAccount::query()->count() === 0,
            'active' => true,
        ]);

        $this->addingAccount = false;
        $this->accountId = $account->id;

        session()->flash('status', 'Bank account added.');
    }

    // ── Statement ───────────────────────────────────────────────────────

    /**
     * Draw the line under everything already settled.
     *
     * Without one, a business three years into trading can never reconcile:
     * every movement it has ever posted counts as "not yet on the statement".
     */
    public function saveOpeningPosition(): void
    {
        Gate::authorize('banking.reconcile');

        $this->validate([
            'openingBalance' => ['required', 'numeric'],
            'openedOn' => ['required', 'date'],
        ], [
            'openedOn.required' => 'Which date was everything last agreed up to?',
        ]);

        $account = $this->account();

        if ($account === null) {
            return;
        }

        $account->forceFill([
            'opening_balance' => (float) $this->openingBalance,
            'opened_on' => $this->openedOn,
        ])->save();

        session()->flash('status', 'Starting point saved. Only movements after that date are open for matching.');
    }

    public function saveStatementBalance(): void
    {
        Gate::authorize('banking.reconcile');

        $this->validate([
            'statementBalance' => ['required', 'numeric'],
            'statementDate' => ['required', 'date'],
        ]);

        $account = $this->account();

        if ($account === null) {
            return;
        }

        $account->forceFill([
            'statement_balance' => (float) $this->statementBalance,
            'statement_date' => $this->statementDate,
        ])->save();

        session()->flash('status', 'Statement balance saved.');
    }

    public function import(): void
    {
        Gate::authorize('banking.import');

        $this->validate([
            'statementFile' => ['required', 'file', 'max:4096'],
        ], [
            'statementFile.required' => 'Choose the CSV your bank exported.',
        ]);

        $account = $this->account();

        if ($account === null) {
            return;
        }

        try {
            $reconciler = app(Reconciler::class);
            $rows = $reconciler->parseCsv(file_get_contents($this->statementFile->getRealPath()));
            $result = $reconciler->import($account, $rows, auth()->user());

            $this->importing = false;
            $this->statementFile = null;

            session()->flash('status', $result['skipped'] > 0
                ? "{$result['imported']} lines imported, {$result['skipped']} already there."
                : "{$result['imported']} lines imported.");
        } catch (RuntimeException $e) {
            $this->addError('statementFile', $e->getMessage());
        }
    }

    public function startAddingLine(): void
    {
        Gate::authorize('banking.import');

        $this->reset(['lineDescription', 'lineAmount', 'lineReference']);
        $this->resetValidation();
        $this->lineDate = now()->toDateString();
        $this->addingLine = true;
    }

    public function saveLine(): void
    {
        Gate::authorize('banking.import');

        $this->validate([
            'lineDate' => ['required', 'date'],
            'lineDescription' => ['required', 'string', 'max:180'],
            'lineAmount' => ['required', 'numeric', 'not_in:0'],
            'lineReference' => ['nullable', 'string', 'max:60'],
        ], [
            'lineAmount.not_in' => 'A movement of nothing is not a movement.',
        ]);

        $account = $this->account();

        if ($account === null) {
            return;
        }

        app(Reconciler::class)->import($account, [[
            'value_date' => $this->lineDate,
            'description' => $this->lineDescription,
            'amount' => (float) $this->lineAmount,
            'reference' => $this->lineReference ?: null,
        ]], auth()->user());

        $this->addingLine = false;

        session()->flash('status', 'Line added.');
    }

    // ── Matching ────────────────────────────────────────────────────────

    public function startMatching(string $id): void
    {
        Gate::authorize('banking.reconcile');

        $this->matching = $this->matching === $id ? null : $id;
        $this->recording = null;
    }

    public function match(string $lineId, string $bookLineId): void
    {
        Gate::authorize('banking.reconcile');

        $line = BankStatementLine::query()->with('bankAccount')->findOrFail($lineId);
        $book = JournalLine::query()->findOrFail($bookLineId);

        try {
            app(Reconciler::class)->match($line, $book, auth()->user());
            $this->matching = null;
            session()->flash('status', 'Matched.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function unmatch(string $lineId): void
    {
        Gate::authorize('banking.reconcile');

        app(Reconciler::class)->unmatch(BankStatementLine::query()->findOrFail($lineId));

        session()->flash('status', 'Unmatched.');
    }

    public function ignore(string $lineId): void
    {
        Gate::authorize('banking.reconcile');

        app(Reconciler::class)->ignore(BankStatementLine::query()->findOrFail($lineId));

        session()->flash('status', 'Set aside.');
    }

    public function startRecording(string $id): void
    {
        Gate::authorize('banking.reconcile');

        $this->recording = $this->recording === $id ? null : $id;
        $this->matching = null;
        // Bank charges are what this is used for nine times in ten.
        $this->counterAccount = '631';
        $this->resetValidation();
    }

    public function recordIntoBooks(): void
    {
        Gate::authorize('banking.reconcile');

        $this->validate([
            'counterAccount' => ['required', 'string', 'max:10'],
        ]);

        $line = BankStatementLine::query()->with('bankAccount.account')->findOrFail($this->recording);

        try {
            app(Reconciler::class)->recordFromStatement($line, $this->counterAccount, auth()->user());
            $this->recording = null;
            session()->flash('status', 'Recorded in the books and matched.');
        } catch (RuntimeException $e) {
            $this->addError('counterAccount', $e->getMessage());
        }
    }

    public function render(): View
    {
        $account = $this->account();

        $lines = $account === null
            ? collect()
            : BankStatementLine::query()
                ->where('bank_account_id', $account->id)
                ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))
                ->orderByDesc('value_date')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();

        $reconciler = app(Reconciler::class);

        // Filled from the record rather than kept in sync by hand, so the
        // fields always show what is actually stored.
        if ($account !== null && $this->openedOn === '') {
            $this->openedOn = $account->opened_on?->toDateString() ?? '';
            $this->openingBalance = (string) $account->opening_balance;
        }

        if ($account !== null && $this->statementBalance === '') {
            $this->statementBalance = (string) $account->statement_balance;
            $this->statementDate = $account->statement_date?->toDateString() ?? $this->statementDate;
        }

        return view('livewire.banking.index', [
            'bankAccount' => $account,
            'accounts' => BankAccount::query()->where('active', true)->orderBy('name')->get(),
            'lines' => $lines,
            'summary' => $account ? $reconciler->summary($account) : null,
            // Suggested matches for the one line the user has open. Computing
            // them for every line would mean a query per row for a panel
            // almost none of them will show.
            'suggestions' => $this->matching !== null && $lines->firstWhere('id', $this->matching)
                ? $reconciler->suggestionsFor($lines->firstWhere('id', $this->matching))
                : collect(),
            'ledgerAccounts' => LedgerAccount::query()
                ->whereIn('class', [5])
                ->orderBy('number')
                ->get(['id', 'number', 'name']),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Banking', 'active' => 'banking']);
    }
}
