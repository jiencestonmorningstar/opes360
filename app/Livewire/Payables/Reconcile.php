<?php

namespace App\Livewire\Payables;

use App\Models\Contact;
use App\Models\Expense;
use App\Models\SupplierStatement;
use App\Models\SupplierStatementLine;
use App\Services\Payables\SupplierReconciler;
use App\Support\CurrentCompany;
use App\Support\SupplierAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * A supplier's statement laid beside our own record of their account.
 *
 * The disagreement is the product. Their balance, ours, and the difference
 * explained line by line stay at the top of the screen; a green tick over three
 * unexplained lines is not a reconciliation, it is somewhere nobody will look
 * again. Nothing here writes to a bill, and the supplier's own closing balance
 * is never recomputed from their own lines — when their total disagrees with
 * their detail that is a finding to put in front of them.
 */
class Reconcile extends Component
{
    use WithFileUploads;

    #[Url(as: 'supplier')]
    public ?string $supplierId = null;

    #[Url(as: 'statement')]
    public ?string $statementId = null;

    #[Url]
    public string $filter = 'unmatched'; // unmatched|matched|disputed|ignored|all

    public bool $importing = false;

    public bool $showOurRecord = false;

    // ── The statement they sent ─────────────────────────────────────────
    public $statementFile;

    public string $statementDate = '';

    public string $periodFrom = '';

    public string $periodTo = '';

    public string $closingBalance = '';

    public string $statementReference = '';

    // ── Working a line ──────────────────────────────────────────────────
    public ?string $matching = null;

    public ?string $disputing = null;

    public string $disputeNote = '';

    public function mount(): void
    {
        Gate::authorize('payables.view');

        $this->statementDate = $this->statementDate ?: now()->toDateString();
        $this->periodFrom = $this->periodFrom ?: now()->startOfMonth()->toDateString();
        $this->periodTo = $this->periodTo ?: now()->toDateString();
    }

    /**
     * Changing supplier drops everything about the last one. A statement panel
     * left open across a change of supplier would be showing one company's
     * arithmetic under another company's name.
     */
    public function updatedSupplierId(): void
    {
        $this->reset(['statementId', 'matching', 'disputing', 'importing']);
    }

    public function selectStatement(string $id): void
    {
        $this->statementId = $id;
        $this->reset(['matching', 'disputing']);
    }

    public function supplier(): ?Contact
    {
        return $this->supplierId === null ? null : Contact::query()->find($this->supplierId);
    }

    public function statement(): ?SupplierStatement
    {
        return $this->statementId === null
            ? null
            : SupplierStatement::query()->with('lines.expense')->find($this->statementId);
    }

    // ── Taking their statement in ───────────────────────────────────────

    public function startImporting(): void
    {
        Gate::authorize('payables.reconcile');

        $this->resetValidation();
        $this->importing = true;
    }

    public function import(): void
    {
        Gate::authorize('payables.reconcile');

        $this->validate([
            'statementFile' => ['required', 'file', 'max:4096'],
            'statementDate' => ['required', 'date'],
            'closingBalance' => ['required', 'numeric'],
        ], [
            'statementFile.required' => 'Choose the file the supplier sent.',
            'closingBalance.required' => 'What do they say the closing balance is? Without their figure there is nothing to disagree with.',
        ]);

        $supplier = $this->supplier();

        if ($supplier === null) {
            return;
        }

        try {
            $reconciler = app(SupplierReconciler::class);
            $rows = $reconciler->parseCsv(file_get_contents($this->statementFile->getRealPath()));

            $statement = $reconciler->import($supplier, [
                'statement_date' => $this->statementDate,
                'period_from' => $this->periodFrom ?: null,
                'period_to' => $this->periodTo ?: null,
                'closing_balance' => (float) $this->closingBalance,
                'reference' => $this->statementReference ?: null,
            ], $rows, auth()->user());

            $this->importing = false;
            $this->statementFile = null;
            $this->statementId = $statement->id;

            session()->flash('status', $statement->lines->count().' lines taken in, exactly as they sent them.');
        } catch (RuntimeException $e) {
            $this->addError('statementFile', $e->getMessage());
        }
    }

    /**
     * Pair the lines that are not in doubt.
     *
     * Conservative on purpose: reference and amount must both agree and only
     * one bill may fit. A wrong automatic match looks reconciled, so nobody
     * ever looks again and the overcharge it papered over is paid every month
     * after.
     */
    public function autoMatch(): void
    {
        Gate::authorize('payables.reconcile');

        $statement = $this->statement();

        if ($statement === null) {
            return;
        }

        $matched = app(SupplierReconciler::class)->autoMatch($statement);

        session()->flash('status', $matched === 0
            ? 'Nothing was safe to match on its own. Every remaining line needs a person to look at it.'
            : $matched.' '.str('line')->plural($matched).' matched. The rest need a person.');
    }

    // ── Working a line ──────────────────────────────────────────────────

    public function startMatching(string $lineId): void
    {
        Gate::authorize('payables.reconcile');

        $this->matching = $this->matching === $lineId ? null : $lineId;
        $this->disputing = null;
    }

    public function match(string $lineId, string $expenseId): void
    {
        Gate::authorize('payables.reconcile');

        try {
            app(SupplierReconciler::class)->match(
                SupplierStatementLine::query()->with('statement')->findOrFail($lineId),
                Expense::query()->findOrFail($expenseId),
            );

            $this->matching = null;
            session()->flash('status', 'Matched. Neither side was changed.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function unmatch(string $lineId): void
    {
        Gate::authorize('payables.reconcile');

        app(SupplierReconciler::class)->unmatch(
            SupplierStatementLine::query()->with('statement')->findOrFail($lineId)
        );

        session()->flash('status', 'Unmatched.');
    }

    public function startDisputing(string $lineId): void
    {
        Gate::authorize('payables.reconcile');

        $this->disputing = $this->disputing === $lineId ? null : $lineId;
        $this->matching = null;
        $this->disputeNote = '';
        $this->resetValidation();
    }

    public function dispute(): void
    {
        Gate::authorize('payables.reconcile');

        $this->validate([
            'disputeNote' => ['required', 'string', 'max:300'],
        ], [
            'disputeNote.required' => 'Say what is wrong with it — a dispute nobody wrote down gets paid next month.',
        ]);

        try {
            app(SupplierReconciler::class)->dispute(
                SupplierStatementLine::query()->with('statement')->findOrFail($this->disputing),
                trim($this->disputeNote),
            );

            $this->disputing = null;
            session()->flash('status', 'Marked as disputed. It stays on the statement.');
        } catch (RuntimeException $e) {
            $this->addError('disputeNote', $e->getMessage());
        }
    }

    public function ignore(string $lineId): void
    {
        Gate::authorize('payables.reconcile');

        app(SupplierReconciler::class)->ignore(
            SupplierStatementLine::query()->with('statement')->findOrFail($lineId)
        );

        session()->flash('status', 'Set aside.');
    }

    public function render(): View
    {
        $statement = $this->statement();
        $reconciler = app(SupplierReconciler::class);

        $lines = $statement === null
            ? collect()
            : $statement->lines
                ->when($this->filter !== 'all', fn ($rows) => $rows->where('status', $this->filter))
                ->sortBy('line_date')
                ->values();

        $openLine = $this->matching === null ? null : $lines->firstWhere('id', $this->matching);

        return view('livewire.payables.reconcile', [
            'supplier' => $this->supplier(),
            'suppliers' => Contact::query()
                ->whereIn('type', ['supplier', 'vendor'])
                ->orderBy('name')
                ->limit(500)
                ->get(),
            'statement' => $statement,
            'statements' => $this->supplierId === null
                ? collect()
                : SupplierStatement::query()
                    ->where('supplier_id', $this->supplierId)
                    ->orderByDesc('statement_date')
                    ->limit(24)
                    ->get(),
            'lines' => $lines,
            'summary' => $statement === null ? null : $reconciler->summary($statement),
            // Only for the one line a person has open. A query per row for a
            // panel almost none of them will show is a slow screen for nothing.
            'suggestions' => $openLine === null ? collect() : $reconciler->suggestionsFor($openLine),
            'ourRecord' => $this->showOurRecord && $this->supplier() !== null && Gate::allows('payables.statement-view')
                ? (new SupplierAccount(
                    $this->supplier(),
                    Carbon::parse($this->periodFrom ?: now()->startOfMonth()->toDateString()),
                    Carbon::parse($this->periodTo ?: now()->toDateString()),
                ))->build()
                : null,
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Supplier reconciliation', 'active' => 'payables']);
    }
}
