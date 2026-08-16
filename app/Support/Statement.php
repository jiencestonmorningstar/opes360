<?php

namespace App\Support;

use App\Enums\DocumentType;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Refund;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A statement of account: everything that passed between the business and one
 * customer over a period, in date order, with the balance running down the page.
 *
 * The aging report answers "who do I chase". This answers the question the
 * customer asks back — "for what?" — and it is the document that settles a
 * disagreement between two sets of books. Without it, the only reply a business
 * could make to "our records don't match yours" was to re-send every invoice
 * and let the customer add them up.
 *
 * ── Why it is built from totals, not balances ──────────────────────────────
 *
 * Each document contributes its *total* — what was charged — and each payment
 * contributes separately. Using document balances instead would net the payment
 * into the invoice line and then count it again as its own line, halving the
 * closing balance. It also means a statement can be produced for a past period
 * and still be right about that period, which a balance-based one cannot: a
 * balance only knows about today.
 *
 * ── Opening balance ────────────────────────────────────────────────────────
 *
 * Everything before the period, summarised into one figure. This is the part
 * that is easy to leave out and expensive to leave out: a June statement sent
 * to a customer who has owed money since March, with no opening balance, reads
 * as a claim for June alone — and that is what gets paid.
 */
class Statement
{
    public function __construct(
        protected Contact $contact,
        protected CarbonInterface $from,
        protected CarbonInterface $to,
    ) {}

    /**
     * @return array{
     *     contact: Contact,
     *     from: CarbonInterface,
     *     to: CarbonInterface,
     *     currency: string,
     *     opening_balance: float,
     *     lines: array<int, array<string, mixed>>,
     *     closing_balance: float,
     *     totals: array{charged: float, credited: float, paid: float},
     *     aging: array<string, float>,
     *     open_documents: array<int, array<string, mixed>>,
     * }
     */
    public function build(): array
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to)->endOfDay();

        $opening = $this->balanceBefore($from);

        $lines = $this->movements($from, $to);

        $balance = $opening;
        $charged = 0.0;
        $credited = 0.0;
        $paid = 0.0;

        foreach ($lines as $index => $line) {
            $balance = round($balance + $line['debit'] - $line['credit'], 2);
            $lines[$index]['balance'] = $balance;

            if ($line['kind'] === 'credit_note') {
                $credited += $line['credit'];
            } elseif ($line['kind'] === 'payment') {
                $paid += $line['credit'];
            } else {
                $charged += $line['debit'];
            }
        }

        return [
            'contact' => $this->contact,
            'from' => $from,
            'to' => $to,
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
            'opening_balance' => round($opening, 2),
            'lines' => $lines,
            'closing_balance' => round($balance, 2),
            'totals' => [
                'charged' => round($charged, 2),
                'credited' => round($credited, 2),
                'paid' => round($paid, 2),
            ],
            'aging' => $this->aging($to),
            'open_documents' => $this->openDocuments($to),
        ];
    }

    /**
     * Everything that moved the account inside the period, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function movements(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];

        foreach ($this->documents()->whereBetween('issue_date', [$from, $to])->get() as $document) {
            $sign = $document->type->customerAccountSign();
            $amount = round((float) $document->total, 2);

            $rows[] = [
                'date' => $document->issue_date,
                'kind' => $document->type->isCreditNote() ? 'credit_note' : $document->type->value,
                'reference' => $document->number,
                'description' => $document->type->label(),
                'debit' => $sign > 0 ? $amount : 0.0,
                'credit' => $sign < 0 ? $amount : 0.0,
                'document_id' => $document->id,
                'sequence' => $document->created_at?->getTimestamp() ?? 0,
            ];
        }

        foreach ($this->payments()->whereBetween('received_at', [$from, $to])->get() as $payment) {
            $rows[] = [
                'date' => $payment->received_at,
                'kind' => 'payment',
                'reference' => $payment->reference ?: $payment->receipt?->number,
                'description' => 'Payment — '.($payment->method?->label() ?? 'received'),
                'debit' => 0.0,
                'credit' => round((float) $payment->amount, 2),
                'document_id' => null,
                'sequence' => $payment->created_at?->getTimestamp() ?? 0,
            ];
        }

        foreach ($this->refunds()->whereBetween('refunded_at', [$from, $to])->get() as $refund) {
            // A refund puts the money back in the customer's hand, so it puts
            // the debt back on their account. Leaving it out would show a
            // customer as square with a business that had returned their money.
            $rows[] = [
                'date' => $refund->refunded_at,
                'kind' => 'refund',
                'reference' => $refund->reference,
                'description' => 'Refund'.($refund->reason ? ' — '.$refund->reason : ''),
                'debit' => round((float) $refund->amount, 2),
                'credit' => 0.0,
                'document_id' => null,
                'sequence' => $refund->created_at?->getTimestamp() ?? 0,
            ];
        }

        usort($rows, function (array $a, array $b) {
            $byDate = Carbon::parse($a['date'])->getTimestamp() <=> Carbon::parse($b['date'])->getTimestamp();

            return $byDate !== 0 ? $byDate : $a['sequence'] <=> $b['sequence'];
        });

        return array_values($rows);
    }

    /** The single figure standing at the start of the period. */
    protected function balanceBefore(CarbonInterface $from): float
    {
        $charged = 0.0;

        foreach ($this->documents()->where('issue_date', '<', $from)->get(['type', 'total']) as $document) {
            $charged += $document->type->customerAccountSign() * (float) $document->total;
        }

        $paid = (float) $this->payments()->where('received_at', '<', $from)->sum('amount');
        $refunded = (float) $this->refunds()->where('refunded_at', '<', $from)->sum('amount');

        return round($charged - $paid + $refunded, 2);
    }

    /** Documents that move a customer's account: invoices, debit and credit notes. */
    protected function documents(): Builder
    {
        return Document::query()
            ->where('contact_id', $this->contact->id)
            ->whereIn('type', [
                DocumentType::Invoice->value,
                DocumentType::DebitNote->value,
                DocumentType::CreditNote->value,
            ])
            ->issued()
            ->orderBy('issue_date');
    }

    protected function payments(): Builder
    {
        return Payment::query()
            ->where('contact_id', $this->contact->id)
            ->with('receipt')
            ->orderBy('received_at');
    }

    protected function refunds(): Builder
    {
        return Refund::query()
            ->where('contact_id', $this->contact->id)
            ->orderBy('refunded_at');
    }

    /**
     * How old the still-open documents are at the end of the period.
     *
     * Uses the same buckets as the aging report deliberately: a customer who
     * receives a statement and a business that reads the aging report must be
     * looking at the same arithmetic, or the conversation starts with an
     * argument about the numbers rather than about the money.
     *
     * @return array<string, float>
     */
    protected function aging(CarbonInterface $to): array
    {
        $buckets = array_fill_keys(array_keys(Aging::BUCKETS), 0.0);

        foreach ($this->openDocuments($to) as $row) {
            $buckets[$row['bucket']] += $row['balance'];
        }

        return array_map(fn ($v) => round($v, 2), $buckets);
    }

    /**
     * What is still owed, document by document.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function openDocuments(CarbonInterface $to): array
    {
        $aging = new Aging(Carbon::parse($to)->startOfDay());

        return $this->documents()
            ->whereIn('type', [DocumentType::Invoice->value, DocumentType::DebitNote->value])
            ->where('balance', '>', 0)
            ->where('issue_date', '<=', $to)
            ->get()
            ->map(function (Document $document) use ($aging) {
                $days = $aging->daysOverdue($document->due_date ?? $document->issue_date);

                return [
                    'id' => $document->id,
                    'number' => $document->number,
                    'issue_date' => $document->issue_date,
                    'due_date' => $document->due_date ?? $document->issue_date,
                    'balance' => round((float) $document->balance, 2),
                    'days_overdue' => $days,
                    'bucket' => $aging->bucketFor($days),
                ];
            })
            ->all();
    }
}
