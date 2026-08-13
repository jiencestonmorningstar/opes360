<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\ExpenseRecorder;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Money going out: supplier bills and day-to-day spending.
 *
 * Everything goes through ExpenseRecorder so each expense reaches the books
 * the same way one typed into the screen does — including the SYSCOHADA
 * account its category maps to, which is stored rather than derived at report
 * time so recategorising the list later cannot rewrite what a past month was
 * posted against.
 *
 * `vat_rate` is a fraction (0.1925), matching the screen and the recorder.
 * Sending 19.25 would be a 1,925% expense, so it is bounded here.
 */
class ExpenseController extends ApiController
{
    public function __construct(private readonly ExpenseRecorder $recorder) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('expenses.view');

        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'max:30'],
            'category' => ['sometimes', Rule::in(array_keys(ChartOfAccounts::EXPENSE_CATEGORIES))],
            'supplier_id' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $expenses = Expense::query()
            ->with('supplier')
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['category']), fn (Builder $q) => $q->where('category', $filters['category']))
            ->when(isset($filters['supplier_id']), fn (Builder $q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(isset($filters['from']), fn (Builder $q) => $q->whereDate('issue_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->whereDate('issue_date', '<=', $filters['to']))
            ->latest('issue_date')
            ->paginate($filters['per_page'] ?? 25);

        return ExpenseResource::collection($expenses);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('expenses.create');

        $data = $request->validate($this->rules());

        $expense = $this->recorder->record($data, $request->user());

        return ExpenseResource::make($expense->load('supplier'))
            ->response()->setStatusCode(201);
    }

    public function show(Expense $expense): ExpenseResource
    {
        $this->authorize('expenses.view');

        return ExpenseResource::make($expense->load('supplier'));
    }

    /** Paying a bill, in part or in full. */
    public function settle(Request $request, Expense $expense): ExpenseResource|JsonResponse
    {
        $this->authorize('expenses.pay');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(array_keys(Expense::METHODS))],
            'reference' => ['nullable', 'string', 'max:60'],
            'paid_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->recorder->settle($expense, $data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ExpenseResource::make($expense->fresh()->load('supplier'));
    }

    /**
     * Cancel one recorded in error.
     *
     * Voided rather than deleted, and its journal entry reversed rather than
     * removed — "what did the books say in March" has to keep having an
     * answer. That is why there is no destroy route.
     */
    public function void(Request $request, Expense $expense): ExpenseResource|JsonResponse
    {
        $this->authorize('expenses.void');

        try {
            $this->recorder->void($expense, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ExpenseResource::make($expense->fresh()->load('supplier'));
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:180'],
            'category' => ['required', Rule::in(array_keys(ChartOfAccounts::EXPENSE_CATEGORIES))],
            'supplier_id' => ['nullable', 'string', 'exists:contacts,id'],
            'reference' => ['nullable', 'string', 'max:60'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            // A fraction, not a percentage — see the class docblock.
            'vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'payment_method' => ['nullable', Rule::in(array_keys(Expense::METHODS))],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
