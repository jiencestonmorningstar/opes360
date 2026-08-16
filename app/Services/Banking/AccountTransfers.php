<?php

namespace App\Services\Banking;

use App\Models\AccountTransfer;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\Ledger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moving money between the business's own accounts.
 *
 * Posts through Ledger::post() like everything else that touches the books —
 * and deliberately NOT through RecordsBusinessEvents::recordQuietly(), which
 * swallows a posting failure so that a sale still completes when the
 * bookkeeping behind it fails. That trade is right for a sale: the customer
 * is standing at the counter and the money has changed hands regardless.
 *
 * It is wrong here. A transfer *is* the bookkeeping — there is no
 * counter-side event that happened anyway. A transfer that silently failed to
 * post would be money that left one account and arrived nowhere, discovered
 * at the next reconciliation with nothing to explain it. So this lets the
 * exception through.
 */
class AccountTransfers
{
    public function record(
        Company $company,
        LedgerAccount $from,
        LedgerAccount $to,
        float $amount,
        string $transferredOn,
        ?User $actor = null,
        ?string $reference = null,
        ?string $narration = null,
    ): AccountTransfer {
        if ($amount <= 0) {
            throw new RuntimeException('A transfer must move a positive amount.');
        }

        if ($from->id === $to->id) {
            throw new RuntimeException('A transfer needs two different accounts.');
        }

        return DB::transaction(function () use ($company, $from, $to, $amount, $transferredOn, $actor, $reference, $narration) {
            $transfer = AccountTransfer::create([
                'company_id' => $company->id,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'transferred_on' => $transferredOn,
                'amount' => $amount,
                'reference' => $reference,
                'narration' => $narration,
                'created_by' => $actor?->id,
            ]);

            $description = $narration ?? "Transfer from {$from->name} to {$to->name}";

            app(Ledger::class)->post(
                company: $company,
                journal: 'BQ',
                entryDate: $transferredOn,
                lines: [
                    ['account' => $to, 'debit' => $amount, 'narration' => $description],
                    ['account' => $from, 'credit' => $amount, 'narration' => $description],
                ],
                // The morph makes posting idempotent: a retried request finds
                // the entry already recorded rather than doubling the books.
                source: $transfer,
                narration: $description,
                reference: $reference,
                actor: $actor,
            );

            return $transfer->fresh();
        });
    }
}
