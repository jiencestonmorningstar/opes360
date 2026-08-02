<?php

namespace App\Livewire\Expenses;

use App\Models\Contact;
use App\Models\Expense;
use App\Services\ExpenseRecorder;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * What the business spends.
 *
 * One list for supplier bills and direct expenses alike; the filter separates
 * them by what is still owed, which is the distinction anyone actually cares
 * about at the end of a month.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $filter = 'all'; // all|owing|overdue|paid

    #[Url]
    public string $period = 'month'; // month|quarter|year|all

    public bool $recording = false;

    /** The expense being settled, if any. */
    public ?string $settling = null;

    public string $description = '';

    public string $category = 'goods';

    public ?string $supplierId = null;

    public string $reference = '';

    public string $issueDate = '';

    public string $dueDate = '';

    public string $amount = '';

    public string $vatRate = '0';

    public string $paymentMethod = 'cash';

    public string $notes = '';

    public string $payAmount = '';

    public string $payMethod = 'cash';

    public string $payReference = '';

    public function mount(): void
    {
        Gate::authorize('expenses.view');

        $this->issueDate = now()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function startRecording(): void
    {
        Gate::authorize('expenses.create');

        $this->reset(['description', 'reference', 'dueDate', 'amount', 'notes', 'supplierId']);
        $this->resetValidation();
        $this->issueDate = now()->toDateString();
        $this->category = 'goods';
        $this->vatRate = (string) (app(CurrentCompany::class)->get()?->vat_registered ? '0.1925' : '0');
        $this->recording = true;
    }

    public function cancel(): void
    {
        $this->recording = false;
        $this->settling = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('expenses.create');

        $this->validate([
            'description' => ['required', 'string', 'max:180'],
            'category' => ['required', 'in:'.implode(',', array_keys(ChartOfAccounts::EXPENSE_CATEGORIES))],
            'supplierId' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:60'],
            'issueDate' => ['required', 'date'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:issueDate'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'vatRate' => ['required', 'numeric', 'min:0', 'max:1'],
            'paymentMethod' => ['required', 'in:'.implode(',', array_keys(Expense::METHODS))],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'description.required' => 'What was the money spent on?',
            'amount.min' => 'An expense has to be for more than nothing.',
            'dueDate.after_or_equal' => 'A bill cannot fall due before it was issued.',
        ]);

        app(ExpenseRecorder::class)->record([
            'supplier_id' => $this->supplierId ?: null,
            'description' => $this->description,
            'category' => $this->category,
            'reference' => $this->reference ?: null,
            'issue_date' => $this->issueDate,
            // A due date is what makes it a bill rather than a cash purchase.
            'due_date' => $this->dueDate ?: null,
            'amount' => (float) $this->amount,
            'vat_rate' => (float) $this->vatRate,
            'payment_method' => $this->dueDate ? null : $this->paymentMethod,
            'notes' => $this->notes ?: null,
        ], auth()->user());

        $this->recording = false;
        $this->reset(['description', 'reference', 'dueDate', 'amount', 'notes', 'supplierId']);
        $this->resetPage();

        session()->flash('status', 'Expense recorded.');
    }

    public function startSettling(string $id): void
    {
        Gate::authorize('expenses.pay');

        $expense = Expense::query()->find($id);

        if ($expense === null || $expense->isPaid() || $expense->isVoid()) {
            return;
        }

        $this->settling = $id;
        $this->payAmount = (string) $expense->balance();
        $this->payMethod = 'cash';
        $this->payReference = '';
        $this->resetValidation();
    }

    public function pay(): void
    {
        Gate::authorize('expenses.pay');

        $this->validate([
            'payAmount' => ['required', 'numeric', 'min:0.01'],
            'payMethod' => ['required', 'in:'.implode(',', array_keys(Expense::METHODS))],
            'payReference' => ['nullable', 'string', 'max:60'],
        ]);

        $expense = Expense::query()->findOrFail($this->settling);

        try {
            app(ExpenseRecorder::class)->settle($expense, [
                'amount' => (float) $this->payAmount,
                'method' => $this->payMethod,
                'reference' => $this->payReference ?: null,
                'paid_on' => now()->toDateString(),
            ], auth()->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['payAmount' => $e->getMessage()]);
        }

        $this->settling = null;

        session()->flash('status', 'Payment recorded.');
    }

    public function void(string $id): void
    {
        Gate::authorize('expenses.void');

        $expense = Expense::query()->find($id);

        if ($expense === null) {
            return;
        }

        try {
            app(ExpenseRecorder::class)->void($expense, auth()->user());
            session()->flash('status', 'Expense voided and its entry reversed.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /** The window the totals and the list both use. */
    protected function since(): ?string
    {
        return match ($this->period) {
            'month' => now()->startOfMonth()->toDateString(),
            'quarter' => now()->startOfQuarter()->toDateString(),
            'year' => now()->startOfYear()->toDateString(),
            default => null,
        };
    }

    public function render(): View
    {
        $since = $this->since();

        $base = fn () => Expense::query()
            ->where('status', '!=', 'void')
            ->when($since, fn ($q) => $q->whereDate('issue_date', '>=', $since));

        $expenses = $base()
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(fn ($q) => $q->where('description', 'like', $term)
                    ->orWhere('reference', 'like', $term));
            })
            ->when($this->filter === 'owing', fn ($q) => $q->whereColumn('amount_paid', '<', 'total'))
            ->when($this->filter === 'paid', fn ($q) => $q->whereColumn('amount_paid', '>=', 'total'))
            ->when($this->filter === 'overdue', fn ($q) => $q
                ->whereColumn('amount_paid', '<', 'total')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString()))
            ->with('supplier:id,first_name,last_name,company_name')
            ->latest('issue_date')
            ->latest('created_at')
            ->paginate(20);

        // Totals from SQL rather than the paginated page, or "spent this month"
        // would only ever count the twenty rows on screen.
        $spent = (float) $base()->sum('total');
        $owing = (float) $base()
            ->whereColumn('amount_paid', '<', 'total')
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) AS owed')
            ->value('owed');

        return view('livewire.expenses.index', [
            'expenses' => $expenses,
            'spent' => $spent,
            'owing' => $owing,
            'categories' => ChartOfAccounts::expenseCategoryOptions(),
            'suppliers' => Contact::query()
                ->whereIn('type', ['supplier', 'vendor'])
                ->orderBy('company_name')
                ->get(['id', 'first_name', 'last_name', 'company_name']),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Expenses', 'active' => 'expenses']);
    }
}
