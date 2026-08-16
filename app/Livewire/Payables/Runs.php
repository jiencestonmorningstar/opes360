<?php

namespace App\Livewire\Payables;

use App\Models\Expense;
use App\Models\PaymentRun;
use App\Models\PaymentRunItem;
use App\Services\Payables\PaymentScheduler;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Payment runs: draft, approved, paid.
 *
 * Three states because they are three different acts done by three different
 * levels of trust. Building a run is clerical; approving it is a signature;
 * releasing it empties the bank account. The screen keeps them apart on purpose
 * — approve and release are never two ordinary buttons side by side, and the
 * release is spelled out in money and suppliers before it will happen.
 */
class Runs extends Component
{
    #[Url]
    public ?string $runId = null;

    #[Url]
    public string $filter = 'open'; // open|executed|all

    // ── Adding a bill the plan did not choose ───────────────────────────
    public bool $adding = false;

    public string $addExpenseId = '';

    public string $addAmount = '';

    // ── Striking a line out ─────────────────────────────────────────────
    public ?string $skipping = null;

    public string $skipNote = '';

    /**
     * The deliberate pause in front of the money.
     *
     * Held as the run's own id rather than a boolean so that a stale confirm
     * panel cannot end up attached to a different run than the one it was
     * opened on.
     */
    public ?string $confirmingExecute = null;

    /** @var array{paid: int, total: float, failed: array<int, array<string, string>>}|null */
    public ?array $result = null;

    public function mount(): void
    {
        Gate::authorize('payables.view');
    }

    public function selectRun(string $id): void
    {
        $this->runId = $id;
        $this->reset(['adding', 'skipping', 'confirmingExecute', 'result']);
    }

    public function run(): ?PaymentRun
    {
        return $this->runId === null
            ? null
            : PaymentRun::query()
                ->with(['items.expense.supplier', 'creator', 'approver'])
                ->find($this->runId);
    }

    // ── Editing the run ─────────────────────────────────────────────────

    public function startAdding(): void
    {
        Gate::authorize('payables.manage');

        $this->reset(['addExpenseId', 'addAmount']);
        $this->resetValidation();
        $this->adding = true;
    }

    public function addBill(): void
    {
        Gate::authorize('payables.manage');

        $this->validate([
            'addExpenseId' => ['required', 'string'],
            'addAmount' => ['nullable', 'numeric', 'min:1'],
        ], [
            'addExpenseId.required' => 'Choose the bill to add.',
        ]);

        $run = $this->run();

        if ($run === null) {
            return;
        }

        try {
            app(PaymentScheduler::class)->addBill(
                $run,
                Expense::query()->findOrFail($this->addExpenseId),
                trim($this->addAmount) === '' ? null : (float) $this->addAmount,
            );

            $this->adding = false;
            $this->reset(['addExpenseId', 'addAmount']);

            session()->flash('status', 'Bill added to the run.');
        } catch (RuntimeException $e) {
            $this->addError('addExpenseId', $e->getMessage());
        }
    }

    public function startSkipping(string $itemId): void
    {
        Gate::authorize('payables.manage');

        $this->skipping = $this->skipping === $itemId ? null : $itemId;
        $this->skipNote = '';
        $this->resetValidation();
    }

    public function skip(): void
    {
        Gate::authorize('payables.manage');

        $item = PaymentRunItem::query()->with('run')->findOrFail($this->skipping);

        try {
            app(PaymentScheduler::class)->skip($item, $this->skipNote ?: null);
            $this->skipping = null;

            session()->flash('status', 'Line struck out. It stays on the run so the reason survives.');
        } catch (RuntimeException $e) {
            $this->addError('skipNote', $e->getMessage());
        }
    }

    public function cancel(): void
    {
        Gate::authorize('payables.manage');

        $run = $this->run();

        if ($run === null) {
            return;
        }

        try {
            app(PaymentScheduler::class)->cancel($run);
            $this->reset(['confirmingExecute', 'adding', 'skipping']);

            session()->flash('status', 'Run cancelled.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── The two acts that are not editing ───────────────────────────────

    public function approve(): void
    {
        Gate::authorize('payables.approve');

        $run = $this->run();

        if ($run === null) {
            return;
        }

        try {
            app(PaymentScheduler::class)->approve($run, auth()->user());

            session()->flash('status', 'Approved. The money has not moved — it still has to be released.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function startExecuting(): void
    {
        Gate::authorize('payables.execute');

        $this->confirmingExecute = $this->runId;
        $this->reset(['adding', 'skipping', 'result']);
    }

    /**
     * Release the money.
     *
     * The service refuses an unapproved run, and that refusal is the only thing
     * between a misclick and an emptied bank account — so its complaint is put
     * in front of the person rather than swallowed.
     */
    public function execute(): void
    {
        Gate::authorize('payables.execute');

        $run = $this->run();

        if ($run === null) {
            return;
        }

        try {
            $this->result = app(PaymentScheduler::class)->execute($run, auth()->user());
            $this->confirmingExecute = null;

            session()->flash('status', 'Paid.');
        } catch (RuntimeException $e) {
            $this->confirmingExecute = null;
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $run = $this->run();

        $runs = PaymentRun::query()
            ->with('items')
            ->when($this->filter === 'open', fn ($q) => $q->open())
            ->when($this->filter === 'executed', fn ($q) => $q->where('status', PaymentRun::STATUS_EXECUTED))
            ->orderByDesc('scheduled_for')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        return view('livewire.payables.runs', [
            'run' => $run,
            'runs' => $runs,
            // Only offered while the run can still change, and only bills that
            // are not already on it: a picker full of things the service will
            // refuse is a screen that teaches people to expect errors.
            'addable' => $run !== null && $run->isEditable() && $this->adding
                ? Expense::query()
                    ->whereNotNull('supplier_id')
                    ->where('status', '!=', 'void')
                    ->whereColumn('amount_paid', '<', 'total')
                    ->whereNotIn('id', $run->items->pluck('expense_id')->filter()->all())
                    ->with('supplier')
                    ->orderBy('due_date')
                    ->limit(200)
                    ->get()
                : collect(),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Payment runs', 'active' => 'payables']);
    }
}
