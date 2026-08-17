<?php

namespace App\Livewire\Expenses;

use App\Models\CostCentre;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Services\ExpenseClaimService;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Expense claims: what a member of staff paid for themselves and wants back.
 *
 * The screen is deliberately thin. Everything that matters — line totals,
 * cross-tenant cost centres, the approval, the bookkeeping on approval and
 * reimbursement — lives in ExpenseClaimService, and this component only
 * gathers the form and relays the service's refusals. Approving does not
 * happen here at all: the workflow inbox owns that, because a second place
 * to approve something is how a product ends up with two answers.
 */
class Claims extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    // ── Create form ─────────────────────────────────────────────────────
    public bool $creating = false;

    public ?string $employeeId = null;

    public string $title = '';

    public string $claimDate = '';

    public string $notes = '';

    /** @var array<int, array{description: string, category: string, amount: string, vat_rate: string, cost_centre_id: string}> */
    public array $lines = [];

    // ── Reimburse form ──────────────────────────────────────────────────
    public ?string $reimbursing = null;

    public string $payAmount = '';

    public string $payMethod = 'cash';

    public string $payReference = '';

    public string $payDate = '';

    public function mount(): void
    {
        Gate::authorize('expenses.claim-view');

        $this->claimDate = now()->toDateString();
        $this->payDate = now()->toDateString();
        $this->lines = [$this->blankLine()];
    }

    /** @return array{description: string, category: string, amount: string, vat_rate: string, cost_centre_id: string} */
    protected function blankLine(): array
    {
        return ['description' => '', 'category' => 'transport', 'amount' => '', 'vat_rate' => '', 'cost_centre_id' => ''];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->blankLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(): void
    {
        Gate::authorize('expenses.claim-create');

        $this->validate([
            'employeeId' => ['required', 'string'],
            'title' => ['required', 'string', 'max:160'],
            'claimDate' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        // Only rows somebody actually filled in count — the form always shows
        // a trailing blank row, and the service (rightly) refuses a claim of
        // nothing.
        $filled = array_values(array_filter(
            $this->lines,
            fn (array $line) => trim($line['description']) !== '' && (float) $line['amount'] > 0,
        ));

        if ($filled === []) {
            $this->addError('lines', 'A claim needs at least one expense on it.');

            return;
        }

        try {
            app(ExpenseClaimService::class)->create([
                'employee_id' => $this->employeeId,
                'title' => $this->title,
                'claim_date' => $this->claimDate,
                'notes' => $this->notes ?: null,
                'lines' => array_map(fn (array $line) => [
                    'description' => $line['description'],
                    'category' => $line['category'],
                    'amount' => (float) $line['amount'],
                    'vat_rate' => (float) ($line['vat_rate'] ?: 0),
                    'cost_centre_id' => $line['cost_centre_id'] ?: null,
                    'incurred_on' => $this->claimDate,
                ], $filled),
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        $this->reset('creating', 'employeeId', 'title', 'notes');
        $this->claimDate = now()->toDateString();
        $this->lines = [$this->blankLine()];
        $this->dispatch('toast', message: 'Claim saved as a draft.');
    }

    public function submit(string $id): void
    {
        Gate::authorize('expenses.claim-create');

        $claim = ExpenseClaim::findOrFail($id);

        try {
            app(ExpenseClaimService::class)->submit($claim, null, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('claims', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Claim sent for approval.');
    }

    public function startReimburse(string $id): void
    {
        Gate::authorize('expenses.claim-reimburse');

        $claim = ExpenseClaim::findOrFail($id);

        $this->reimbursing = $claim->id;
        // Defaults to the whole balance because that is the ordinary case;
        // partial payment stays possible by editing the figure.
        $this->payAmount = (string) $claim->balance();
        $this->payMethod = 'cash';
        $this->payReference = '';
        $this->payDate = now()->toDateString();
        $this->resetErrorBag('claims');
    }

    public function reimburse(): void
    {
        Gate::authorize('expenses.claim-reimburse');

        $this->validate([
            'payAmount' => ['required', 'numeric', 'min:0.01'],
            'payMethod' => ['required', 'in:'.implode(',', array_keys(ExpenseClaim::METHODS))],
            'payDate' => ['required', 'date'],
            'payReference' => ['nullable', 'string', 'max:120'],
        ]);

        $claim = ExpenseClaim::findOrFail($this->reimbursing);

        try {
            app(ExpenseClaimService::class)->reimburse($claim, [
                'amount' => (float) $this->payAmount,
                'method' => $this->payMethod,
                'paid_on' => $this->payDate,
                'reference' => $this->payReference ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('claims', $e->getMessage());

            return;
        }

        $this->reimbursing = null;
        $this->dispatch('toast', message: 'Reimbursement recorded.');
    }

    public function render(): View
    {
        return view('livewire.expenses.claims', [
            'claims' => ExpenseClaim::query()
                ->with(['employee', 'lines'])
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->latest()
                ->paginate(20),
            'employees' => Employee::query()
                ->where('status', 'active')
                ->orderBy('first_name')
                ->get(),
            'costCentres' => CostCentre::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'categories' => ChartOfAccounts::expenseCategoryOptions(),
            'statuses' => ExpenseClaim::STATUSES,
            'methods' => ExpenseClaim::METHODS,
        ])->layout('components.layouts.app', ['title' => 'Expense claims', 'active' => 'expenses']);
    }
}
