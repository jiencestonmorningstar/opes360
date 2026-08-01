<?php

namespace App\Services\Accounting;

use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The two business events that belong in the books, expressed as entries.
 *
 * Issuing an invoice for 119 250 TTC on 100 000 HT:
 *
 *   411 Clients              119 250 D    what the customer now owes
 *   701 Ventes                          C  100 000    the income earned
 *   443 État, TVA facturée              C   19 250    tax collected on the state's behalf
 *
 * The TVA is split out rather than folded into income for the reason it is
 * split out on the invoice: it was never the business's money. Taking payment
 * moves the same total from the customer to the till or the bank:
 *
 *   571 Caisse               119 250 D
 *   411 Clients                         C  119 250
 *
 * Both are posted through Ledger, so both are balanced and neither can be
 * recorded twice for the same source.
 */
class RecordsBusinessEvents
{
    public function __construct(protected Ledger $ledger) {}

    /**
     * Records an issued sales document. Drafts are not recorded: nothing is
     * owed until a document is issued, and a draft can still change.
     */
    public function recordIssuedDocument(Document $document, Company $company, ?User $actor = null): ?JournalEntry
    {
        if (! $this->chartIsReady($company)) {
            return null;
        }

        $net = round((float) $document->subtotal, 2);
        $tax = round((float) $document->tax_total, 2);
        $gross = round((float) $document->total, 2);

        if ($gross <= 0) {
            return null;
        }

        $lines = [
            ['account' => 'receivables', 'debit' => $gross, 'narration' => $document->contact?->displayName()],
        ];

        foreach ($this->revenueByAccount($document, $net) as $role => $amount) {
            $lines[] = ['account' => $role, 'credit' => $amount];
        }

        if ($tax > 0) {
            $lines[] = ['account' => 'vat_collected', 'credit' => $tax];
        }

        return $this->ledger->post(
            company: $company,
            journal: 'VE',
            entryDate: $document->issue_date?->toDateString() ?? now()->toDateString(),
            lines: $lines,
            source: $document,
            narration: trim($document->type->label().' '.($document->number ?? '')),
            reference: $document->number,
            actor: $actor,
        );
    }

    /**
     * Splits a document's net across revenue accounts.
     *
     * A line that points at a catalogue item knows whether it sold a thing or
     * an hour, so it books itself: services to 706, goods to 701. A line typed
     * freehand — which the composer allows and most people use — knows nothing,
     * and falls back to whatever the business says it mostly sells.
     *
     * Rounding is settled against the total rather than accumulated: the last
     * account absorbs the difference, so the credits always sum to exactly the
     * net the invoice shows and the entry cannot fail to balance over a franc.
     *
     * @return array<string, float> role => amount
     */
    protected function revenueByAccount(Document $document, float $net): array
    {
        $default = in_array($document->company?->default_sales_account, ['sales_goods', 'sales_services'], true)
            ? $document->company->default_sales_account
            : 'sales_goods';

        $document->loadMissing('lines.item');

        $lines = $document->lines;

        if ($lines->isEmpty()) {
            return [$default => $net];
        }

        $split = [];

        foreach ($lines as $line) {
            $role = match ($line->item?->type) {
                'service' => 'sales_services',
                'product' => 'sales_goods',
                default => $default,
            };

            $split[$role] = round(($split[$role] ?? 0) + (float) $line->line_total, 2);
        }

        $split = array_filter($split, fn (float $amount) => $amount != 0.0);

        if ($split === []) {
            return [$default => $net];
        }

        // Reconcile to the document's own net. Line totals are already rounded
        // individually, and the invoice total is what the customer was shown.
        $drift = round($net - array_sum($split), 2);

        if ($drift != 0.0) {
            $last = array_key_last($split);
            $split[$last] = round($split[$last] + $drift, 2);
        }

        return $split;
    }

    /**
     * Records a payment against the till or the bank, depending on how it
     * arrived. Cash and mobile money go to the caisse; transfers and cards to
     * the bank, since that is where the money actually lands.
     */
    public function recordPayment(Payment $payment, Company $company, ?User $actor = null): ?JournalEntry
    {
        if (! $this->chartIsReady($company)) {
            return null;
        }

        $amount = round((float) $payment->amount, 2);

        if ($amount <= 0) {
            return null;
        }

        $destination = in_array($payment->method, [PaymentMethod::Cash, PaymentMethod::MobileMoney], true)
            ? 'cash'
            : 'bank';

        return $this->ledger->post(
            company: $company,
            journal: $destination === 'cash' ? 'CA' : 'BQ',
            entryDate: $payment->received_at?->toDateString() ?? now()->toDateString(),
            lines: [
                ['account' => $destination, 'debit' => $amount, 'narration' => $payment->method?->label()],
                ['account' => 'receivables', 'credit' => $amount, 'narration' => $payment->contact?->displayName()],
            ],
            source: $payment,
            narration: 'Règlement '.($payment->reference ?? ''),
            reference: $payment->reference,
            actor: $actor,
        );
    }

    /**
     * Bookkeeping must never be the reason a sale fails.
     *
     * A business that cannot issue an invoice because its chart of accounts is
     * half configured has a worse problem than one whose books need catching
     * up — the customer is standing at the counter either way. Failures are
     * logged and the entry can be posted later; the sale goes through.
     */
    public function recordQuietly(callable $record): ?JournalEntry
    {
        try {
            return $record();
        } catch (Throwable $e) {
            Log::warning('Ledger posting failed; the business event was not recorded in the books.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** A company with no chart is not keeping books yet, which is allowed. */
    protected function chartIsReady(Company $company): bool
    {
        return ChartOfAccounts::account($company, 'receivables') !== null;
    }
}
