<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Charging a customer more after the invoice has gone out.
 *
 * The mirror of `DocumentConverter::creditNote()`, and the half of the pair
 * this platform did not have. An issued invoice is frozen (decisions.md #6), so
 * a business that undercharged had two bad options: edit the invoice, which the
 * model layer refuses, or raise a second invoice — which pretends a second sale
 * happened, takes the goods off the shelf twice, and leaves the customer with
 * two documents for one delivery.
 *
 * A debit note says exactly what it is: an additional amount owed on an
 * existing account. The books already knew how to treat one — `DocumentType`
 * counts it as receivable and gives it a customer-account sign of +1, so the
 * journal entry, the customer's balance and the aging all follow from issuing
 * it. What was missing was any way to make one.
 *
 * Nothing here moves stock. A debit note is money, not goods: an undercharge, a
 * price correction, a late-payment fee, a rebilled freight cost. Where extra
 * goods really did leave the shelf, that is a second sale and belongs on a
 * second invoice.
 */
class DebitNotes
{
    public function __construct(protected DocumentIssuer $issuer) {}

    /**
     * Raise and issue a debit note.
     *
     * Issued straight away rather than left as a draft, for the same reason a
     * credit note is: it exists because somebody has decided a customer owes
     * more, and a draft that says so changes nothing about what is owed while
     * looking as though it has.
     *
     * @param  float  $amount  gross — what the customer will see added to their
     *                         account, tax included
     * @param  Document|null  $against  the invoice being corrected, when there
     *                                  is one
     * @param  Contact|null  $customer  required only for a standalone note; a
     *                                  note against an invoice takes the
     *                                  invoice's customer
     * @param  float|null  $taxRate  e.g. 0.1925. Ignored when `$against` is
     *                               given, whose own effective rate is used
     *                               instead.
     */
    public function raise(
        User $user,
        float $amount,
        string $reason,
        ?Document $against = null,
        ?Contact $customer = null,
        ?float $taxRate = null,
    ): Document {
        $reason = trim($reason);
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('A debit note for nothing is not a debit note.');
        }

        /*
         * A debit note arrives at a customer who believed they had settled up.
         * Without a stated reason it reads as an error or an invention, and the
         * first thing that happens is a dispute — which costs more than the
         * charge is usually worth.
         */
        if ($reason === '') {
            throw new RuntimeException('A debit note must say what it is for.');
        }

        if ($against !== null) {
            if ($against->type !== DocumentType::Invoice) {
                throw new RuntimeException('Only an invoice can be debited.');
            }

            if ($against->status === DocumentStatus::Draft || $against->status === DocumentStatus::Void) {
                throw new RuntimeException('A draft or voided invoice has nothing to correct.');
            }

            // Loaded explicitly: lazy loading is disabled app-wide, and a
            // document handed in from a query rather than freshly created
            // would otherwise trip the guard here rather than at the call site.
            $against->loadMissing('contact');

            $customer = $against->contact ?? $customer;
        }

        if ($customer === null && $against?->contact_id === null) {
            throw new RuntimeException('A debit note has to be charged to somebody.');
        }

        [$net, $tax] = $this->split($amount, $against, $taxRate);

        $contactId = $against?->contact_id ?? $customer?->id;
        $terms = ($against?->contact ?? $customer)?->payment_terms_days ?? 14;

        return DB::transaction(function () use ($user, $amount, $net, $tax, $reason, $against, $contactId, $terms) {
            $note = Document::create([
                'type' => DocumentType::DebitNote,
                'contact_id' => $contactId,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                // Unlike a credit note, this one *is* owed on a date — it is a
                // demand for money, and a demand with no due date can never be
                // overdue, so dunning and the collections queue would never see
                // it.
                'due_date' => now()->addDays($terms)->toDateString(),
                'currency' => $against?->currency ?? auth()->user()?->currentCompany?->currency ?? 'XAF',
                // Explicitly 1 rather than null for a standalone note: the
                // column is NOT NULL with a default, and passing null through
                // mass assignment sets null rather than falling back to it.
                'exchange_rate' => $against?->exchange_rate ?? 1,
                'subtotal' => $net,
                'discount_total' => 0,
                'tax_total' => $tax,
                'total' => $amount,
                'amount_paid' => 0,
                'balance' => $amount,
                'notes' => $reason,
                'parent_document_id' => $against?->id,
                'created_by' => $user->id,
            ]);

            DocumentLine::create([
                'document_id' => $note->id,
                'description' => $against?->number
                    ? sprintf('Complément sur facture %s — %s', $against->number, $reason)
                    : $reason,
                'quantity' => 1,
                'unit' => 'unit',
                'unit_price' => $net,
                'tax_amount' => $tax,
                'line_total' => $net,
                'sort_order' => 0,
            ]);

            return $this->issuer->issue($note, $user);
        });
    }

    /** What the live debit notes against an invoice come to. */
    public function debitedTotal(Document $invoice): float
    {
        return round((float) Document::query()
            ->where('parent_document_id', $invoice->id)
            ->ofType(DocumentType::DebitNote)
            ->issued()
            ->sum('total'), 2);
    }

    /**
     * Split a gross amount into net and tax.
     *
     * Against an invoice, the rate is the invoice's own effective rate rather
     * than the company default: a correction to a zero-rated export must not
     * arrive carrying 19.25% TVA the original sale never charged.
     *
     * @return array{0: float, 1: float} [net, tax]
     */
    protected function split(float $amount, ?Document $against, ?float $taxRate): array
    {
        if ($against !== null) {
            $gross = (float) $against->total;
            $tax = $gross > 0 ? round($amount * ((float) $against->tax_total / $gross), 2) : 0.0;

            return [round($amount - $tax, 2), $tax];
        }

        // No rate given means none applies. A late-payment charge is
        // compensation rather than consideration for a supply, and is outside
        // the scope of VAT in the OHADA/CEMAC regimes this platform serves —
        // so silently adding tax would overstate what the business owes the
        // state.
        if ($taxRate === null || $taxRate <= 0) {
            return [$amount, 0.0];
        }

        $tax = round($amount * $taxRate / (1 + $taxRate), 2);

        return [round($amount - $tax, 2), $tax];
    }
}
