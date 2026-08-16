<?php

namespace App\Http\Controllers\Api;

use App\Services\Accounting\Books;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The books, read only.
 *
 * Read only on purpose, and not as a limitation to be lifted later. Nothing
 * posts to this ledger by hand: entries are the consequence of business events
 * — an invoice issued, a payment taken, an expense settled, a payroll month
 * approved — and each of those already has its own endpoint that posts as a
 * side effect. A "create journal entry" endpoint would be a way to make the
 * books disagree with the documents underneath them, with nothing to say why.
 *
 * Every figure here comes from Books, the same service the accounting screens
 * and the exports read, so a report pulled over HTTP and one printed from the
 * app cannot differ.
 */
class AccountingController extends ApiController
{
    public function __construct(private readonly Books $books) {}

    public function accounts(Request $request): JsonResponse
    {
        $this->authorize('accounting.view');

        $accounts = $this->books->accounts($this->company())
            ->map(fn ($account) => [
                'id' => $account->id,
                'number' => $account->number,
                'name' => $account->name,
                'class' => $account->class,
                'normal_balance' => $account->normal_balance,
                'is_active' => (bool) $account->is_active,
            ]);

        return response()->json(['data' => $accounts->values()]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $this->authorize('accounting.view');

        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->books->trialBalance($this->company(), $from, $to)]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $this->authorize('accounting.view');

        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->books->incomeStatement($this->company(), $from, $to)]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $this->authorize('accounting.view');

        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->books->balanceSheet($this->company(), $from, $to)]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $this->authorize('accounting.view');

        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->books->cashFlow($this->company(), $from, $to)]);
    }

    public function journal(Request $request): JsonResponse
    {
        $this->authorize('accounting.view');

        $data = $request->validate([
            'journal' => ['sometimes', 'string', 'max:10'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $entries = $this->books->journal(
            $this->company(),
            $data['journal'] ?? null,
            $data['from'] ?? null,
            $data['to'] ?? null,
        );

        return response()->json(['data' => $entries->values()]);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        return [$data['from'] ?? null, $data['to'] ?? null];
    }

    private function company()
    {
        return app(CurrentCompany::class)->get();
    }
}
