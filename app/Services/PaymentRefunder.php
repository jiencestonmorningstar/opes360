<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Document;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Support\CurrentCompany;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Giving money back.
 *
 * Recording a payment moves five things at once — the payment row, its
 * allocation, the document's balance and status, the customer's cached
 * balance, the ledger, and any loyalty points earned. A refund has to undo
 * exactly that set, in one transaction, or the business is left with books
 * that disagree with the documents underneath them. That is why this is a
 * service and not a controller action, and why the permission existed for a
 * long time with nothing behind it: the endpoint is the easy part.
 *
 * ── What is undone, and what is not ────────────────────────────────────────
 *
 * The payment stays. The customer is holding a receipt that says money
 * changed hands, that receipt carries a QR anyone can check, and it has to
 * keep verifying — deleting the payment would leave a printed receipt
 * pointing at nothing, which is the exact situation the verification page
 * exists to prevent. So a refund is a second event recorded beside the first.
 *
 * The allocation is reduced, because the invoice genuinely is owed again. The
 * document goes back to partial or issued, its balance rises by the refunded
 * amount, and the customer's cached balance is recomputed from the documents
 * rather than by arithmetic on this one — the same rule the recorder follows.
 *
 * The ledger entry is reversed rather than deleted, so "what did the books
 * say in March" keeps its answer.
 *
 * Loyalty points earned on the payment are taken back in proportion, through
 * the audited adjustment path rather than by editing a balance. A customer who
 * was refunded should not keep points for a purchase that did not stand.
 */
class PaymentRefunder
{
    public function __construct(protected LoyaltyLedger $loyalty) {}

    /**
     * @param  float  $amount  How much to give back. May be less than the
     *                         payment: a customer returning two of five bags
     *                         is refunded for two.
     * @param  PaymentMethod  $method  How the money went back, which is not
     *                                 always how it came in — cash taken at the
     *                                 counter is often returned by mobile money.
     * @param  string  $reason  Required. A refund nobody can explain a year
     *                          later is the one entry in the books that
     *                          matters most and reads least.
     */
    public function refund(
        Payment $payment,
        User $actor,
        float $amount,
        PaymentMethod $method,
        string $reason,
        ?string $reference = null,
        ?CarbonInterface $refundedAt = null,
    ): Refund {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('A refund must be for more than nothing.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A refund needs a reason.');
        }

        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot refund without a current company.');
        }

        $refundedAt ??= now();

        return DB::transaction(function () use ($payment, $actor, $amount, $method, $reason, $reference, $refundedAt, $company) {
            // Re-read under a row lock: two people refunding the same payment
            // at once must not both pass the "still refundable" test on stale
            // data and hand back the money twice.
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $alreadyRefunded = round((float) Refund::where('payment_id', $payment->id)->sum('amount'), 2);
            $refundable = round((float) $payment->amount - $alreadyRefunded, 2);

            if ($amount - $refundable > 0.005) {
                throw new RuntimeException(sprintf(
                    'That is more than is left on this payment. %s of %s has already been refunded.',
                    number_format($alreadyRefunded, 2),
                    number_format((float) $payment->amount, 2),
                ));
            }

            $refund = Refund::create([
                'company_id' => $company->id,
                'payment_id' => $payment->id,
                'contact_id' => $payment->contact_id,
                'amount' => $amount,
                'currency' => $payment->currency,
                'method' => $method,
                'reference' => $reference,
                'reason' => trim($reason),
                'refunded_at' => $refundedAt,
                'refunded_by' => $actor->id,
            ]);

            $this->restoreDocuments($payment, $amount);
            $this->clawBackPoints($payment, $amount, $actor);
            $this->reverseLedger($refund, $payment, $company, $actor);

            return $refund;
        });
    }

    /**
     * Put the money back on what it was paying for.
     *
     * A payment can be spread across several documents, so the refund is taken
     * off them in the order they were allocated — the most recently settled
     * invoice becomes owed again first, which is what somebody looking at the
     * customer's account would expect.
     */
    protected function restoreDocuments(Payment $payment, float $amount): void
    {
        $remaining = $amount;

        $allocations = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            if ($remaining <= 0.005) {
                break;
            }

            $take = min(round((float) $allocation->amount, 2), $remaining);
            $remaining = round($remaining - $take, 2);

            $document = Document::query()->lockForUpdate()->find($allocation->document_id);

            if ($document === null) {
                continue;
            }

            $paid = round(max((float) $document->amount_paid - $take, 0), 2);
            $balance = round((float) $document->total - $paid, 2);

            $document->forceFill([
                'amount_paid' => $paid,
                'balance' => $balance,
                /*
                 * Back to owing. A voided document stays void — refunding
                 * money against something already cancelled must not quietly
                 * bring it back to life.
                 */
                'status' => match (true) {
                    $document->status === DocumentStatus::Void => DocumentStatus::Void,
                    $balance <= 0 => DocumentStatus::Paid,
                    $paid > 0 => DocumentStatus::Partial,
                    default => DocumentStatus::Issued,
                },
            ])->save();

            // Reduce rather than delete: the allocation is the record of what
            // this payment settled, and it settled less than it used to.
            $left = round((float) $allocation->amount - $take, 2);

            $left <= 0
                ? $allocation->delete()
                : $allocation->forceFill(['amount' => $left])->save();
        }

        // From the documents themselves, not by subtracting this refund —
        // the same rule PaymentRecorder follows, and the reason the cached
        // balance can be trusted at all.
        $payment->contact?->recomputeBalance();
    }

    /**
     * Take back the points the payment earned, in proportion to what was
     * given back.
     *
     * Through the audited adjustment path rather than by writing the balance:
     * a customer asking why their points changed deserves an answer, and the
     * adjustment carries the note that gives them one.
     */
    protected function clawBackPoints(Payment $payment, float $amount, User $actor): void
    {
        $contact = $payment->contact;

        if ($contact === null) {
            return;
        }

        $earned = (int) LoyaltyTransaction::query()
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->where('type', 'earn')
            ->sum('points');

        if ($earned <= 0) {
            return;
        }

        $share = (float) $payment->amount > 0 ? $amount / (float) $payment->amount : 0;
        $take = (int) round($earned * $share);

        if ($take <= 0) {
            return;
        }

        /*
         * Never below zero. A customer who earned points and then spent them
         * before returning the goods would otherwise end up with a negative
         * balance, which is a debt the loyalty programme never agreed to
         * create — the adjustment is capped and the shortfall is simply not
         * clawed back.
         */
        $take = min($take, (int) $contact->loyalty_points);

        if ($take <= 0) {
            return;
        }

        // Quietly: a loyalty programme that cannot be adjusted must not be the
        // reason a customer standing at the counter cannot be refunded.
        try {
            $this->loyalty->adjust($contact, -$take, 'Remboursement', $actor);
        } catch (\Throwable) {
        }
    }

    /**
     * The books: money leaves the till or the bank, and the customer owes
     * again.
     *
     * A reversal of the settlement rather than a fresh entry invented here, so
     * the two are visibly a pair in the journal. Wrapped in recordQuietly for
     * the same reason the posting it undoes is — a half-configured chart of
     * accounts must not be why a customer cannot be given their money back.
     */
    protected function reverseLedger(Refund $refund, Payment $payment, Company $company, User $actor): void
    {
        $events = app(RecordsBusinessEvents::class);

        $events->recordQuietly(function () use ($refund, $payment, $company, $actor) {
            $ledger = app(Ledger::class);

            $entry = $ledger->entryFor($company, $payment);

            if ($entry === null) {
                return null;
            }

            /*
             * A full refund reverses the settlement outright. A partial one
             * cannot — reversing the whole entry would credit the till with
             * money that never left it — so it posts its own entry for the
             * part that did.
             */
            $full = abs((float) $refund->amount - (float) $payment->amount) < 0.005;

            if ($full) {
                return $ledger->reverse($entry, $actor, 'Remboursement '.($payment->reference ?? ''));
            }

            $source = in_array($refund->method, [PaymentMethod::Cash, PaymentMethod::MobileMoney], true)
                ? 'cash'
                : 'bank';

            return $ledger->post(
                company: $company,
                journal: $source === 'cash' ? 'CA' : 'BQ',
                entryDate: $refund->refunded_at?->toDateString() ?? now()->toDateString(),
                lines: [
                    ['account' => 'receivables', 'debit' => (float) $refund->amount, 'narration' => $payment->contact?->displayName()],
                    ['account' => $source, 'credit' => (float) $refund->amount, 'narration' => $refund->method?->label()],
                ],
                source: $refund,
                narration: 'Remboursement partiel — '.$refund->reason,
                reference: $refund->reference,
                actor: $actor,
            );
        });
    }
}
