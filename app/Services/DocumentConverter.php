<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Services\Stock\StockLedger;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Converts a document into a downstream one — quotation to invoice, invoice to
 * credit note — and voids documents.
 *
 * Conversion copies rather than mutates: the source stays exactly as issued, and
 * the new document records its parent. That keeps the audit trail intact and
 * respects the immutability rule instead of working around it.
 */
class DocumentConverter
{
    public function __construct(protected DocumentIssuer $issuer) {}

    /** Which type each source converts into. */
    protected const TARGETS = [
        'quotation' => DocumentType::Invoice,
        'proforma' => DocumentType::Invoice,
        // A finished job becomes the bill for it.
        'work_order' => DocumentType::Invoice,
        'invoice' => DocumentType::CreditNote,
    ];

    public function canConvert(Document $document): bool
    {
        if ($document->status === DocumentStatus::Draft || $document->status === DocumentStatus::Void) {
            return false;
        }

        // A source already turned into an invoice must not spawn a second one.
        if (in_array($document->type, [DocumentType::Quotation, DocumentType::Proforma, DocumentType::WorkOrder], true)) {
            return ! Document::query()
                ->where('parent_document_id', $document->id)
                ->invoices()
                ->exists();
        }

        /*
         * An invoice can be credited until it has been credited in full, and
         * not a franc further. Without this an invoice could be converted over
         * and over, each one a full-value credit note, and a customer who owed
         * 100 000 would end up recorded as being owed 200 000 by a business
         * that had done nothing wrong except click twice.
         */
        if ($document->type === DocumentType::Invoice) {
            return $this->creditableAmount($document) > 0.005;
        }

        return array_key_exists($document->type->value, self::TARGETS);
    }

    public function targetType(Document $document): ?DocumentType
    {
        return self::TARGETS[$document->type->value] ?? null;
    }

    /** What the live credit notes against an invoice come to. */
    public function creditedTotal(Document $invoice): float
    {
        return round((float) Document::query()
            ->where('parent_document_id', $invoice->id)
            ->ofType(DocumentType::CreditNote)
            ->issued()
            ->sum('total'), 2);
    }

    /** How much of an invoice is still open to being credited. */
    public function creditableAmount(Document $invoice): float
    {
        return round((float) $invoice->total - $this->creditedTotal($invoice), 2);
    }

    /**
     * Credit part of an invoice.
     *
     * The whole-invoice case goes through `convert()`, which copies the lines,
     * because a full credit note should read like the invoice it cancels — the
     * customer needs to recognise it. A partial credit cannot: there is no
     * honest way to spread 15 000 F across seven lines without inventing which
     * of them the customer was overcharged on. So it is a single line saying
     * what it is, against the invoice it belongs to.
     *
     * The amount is what the customer sees — TTC — split back into net and tax
     * at the invoice's own effective rate, so a 19.25% invoice produces a
     * 19.25% credit note and the TVA the business reclaims is exactly the TVA
     * it charged.
     *
     * @param  float  $amount  gross, in the invoice's currency
     */
    public function creditNote(Document $invoice, User $user, float $amount, ?string $reason = null): Document
    {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new RuntimeException('Only an invoice can be credited.');
        }

        if ($invoice->status === DocumentStatus::Draft || $invoice->status === DocumentStatus::Void) {
            throw new RuntimeException('A draft or voided invoice has nothing to credit.');
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('A credit note for nothing is not a credit note.');
        }

        $available = $this->creditableAmount($invoice);

        if ($amount - $available > 0.005) {
            throw new RuntimeException(sprintf(
                'That is more than is left to credit on this invoice (%s).',
                number_format($available, 2)
            ));
        }

        // Whole invoice, nothing credited yet: copy it, so the credit note is
        // the invoice's mirror image on paper as well as in the books.
        if (abs($amount - (float) $invoice->total) < 0.005 && $this->creditedTotal($invoice) < 0.005) {
            return $this->convert($invoice, $user);
        }

        $gross = (float) $invoice->total;
        $tax = $gross > 0 ? round($amount * ((float) $invoice->tax_total / $gross), 2) : 0.0;
        $net = round($amount - $tax, 2);

        return DB::transaction(function () use ($invoice, $user, $amount, $net, $tax, $reason) {
            $note = Document::create([
                'type' => DocumentType::CreditNote,
                'contact_id' => $invoice->contact_id,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                'currency' => $invoice->currency,
                'exchange_rate' => $invoice->exchange_rate,
                'subtotal' => $net,
                'tax_total' => $tax,
                'total' => $amount,
                'balance' => $amount,
                'notes' => $reason,
                'parent_document_id' => $invoice->id,
                'created_by' => $user->id,
            ]);

            DocumentLine::create([
                'document_id' => $note->id,
                'description' => trim('Avoir sur facture '.($invoice->number ?? '').($reason ? ' — '.$reason : '')),
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

    public function convert(Document $source, User $user): Document
    {
        if (! $this->canConvert($source)) {
            throw new RuntimeException('This document cannot be converted.');
        }

        $target = $this->targetType($source);

        /*
         * A full-value copy is only honest while nothing has been credited yet.
         * Once part of an invoice has been credited, copying it whole would
         * credit more than was ever charged — so that case has to name its
         * amount, which is what creditNote() is for.
         */
        if ($target === DocumentType::CreditNote && $this->creditedTotal($source) > 0.005) {
            throw new RuntimeException(sprintf(
                'Part of this invoice has already been credited. Credit the remaining %s instead.',
                number_format($this->creditableAmount($source), 2)
            ));
        }

        return DB::transaction(function () use ($source, $target, $user) {
            $source->loadMissing('lines');

            $copy = Document::create([
                'type' => $target,
                'contact_id' => $source->contact_id,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                // A credit note is not owed on a date — it is money the
                // business already accepts it is not owed.
                'due_date' => $target === DocumentType::CreditNote
                    ? null
                    : now()->addDays($source->contact?->payment_terms_days ?? 14)->toDateString(),
                'currency' => $source->currency,
                'exchange_rate' => $source->exchange_rate,
                'subtotal' => $source->subtotal,
                'discount_total' => $source->discount_total,
                'tax_total' => $source->tax_total,
                'total' => $source->total,
                'balance' => $source->total,
                'notes' => $source->notes,
                'terms' => $source->terms,
                'parent_document_id' => $source->id,
                'created_by' => $user->id,
            ]);

            foreach ($source->lines as $line) {
                DocumentLine::create([
                    'document_id' => $copy->id,
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => $line->unit_price,
                    'discount_type' => $line->discount_type,
                    'discount_value' => $line->discount_value,
                    'tax_rate_id' => $line->tax_rate_id,
                    'tax_amount' => $line->tax_amount,
                    'line_total' => $line->line_total,
                    'sort_order' => $line->sort_order,
                ]);
            }

            $issued = $this->issuer->issue($copy, $user);

            // An accepted quotation has done its job; marking it closes the loop
            // in the list without editing anything that was printed.
            if ($source->type === DocumentType::Quotation) {
                $source->forceFill(['status' => DocumentStatus::Accepted])->save();
            }

            return $issued;
        });
    }

    /**
     * Voiding is the only way to undo an issued document. The number is retired
     * rather than reused — the lease ledger keeps every allocation auditable —
     * and the verification token is revoked so a scan of the printed copy
     * reports "Voided" rather than still saying it is valid.
     */
    public function void(Document $document, User $user, ?string $reason = null): Document
    {
        if ($document->status === DocumentStatus::Void) {
            throw new RuntimeException('This document is already void.');
        }

        if ((float) $document->amount_paid > 0) {
            throw new RuntimeException(
                'This document has payments against it. Refund or reallocate them before voiding.'
            );
        }

        return DB::transaction(function () use ($document, $user, $reason) {
            $document->forceFill([
                'status' => DocumentStatus::Void,
                'balance' => 0,
            ])->save();

            $document->verificationToken?->forceFill(['revoked_at' => now()])->save();

            $document->approvals()->create([
                'user_id' => $user->id,
                'action' => 'voided',
                'comment' => $reason,
                'created_at' => now(),
            ]);

            $this->undoIssue($document, $user);

            // The voided document is out of the `issued` scope by now, so this
            // simply stops counting it — in whichever direction it counted.
            if ($document->contact && $document->type->affectsCustomerAccount()) {
                $document->contact->recomputeBalance();
            }

            return $document;
        });
    }

    /**
     * Undo what issuing did: extourne the sale and put the goods back.
     *
     * Voiding used to stop at the document and the customer's balance, which
     * left the ledger claiming revenue and a receivable for a sale that had
     * been cancelled — the books said the business had earned money it had
     * publicly said it had not. The entry is reversed rather than deleted, so
     * March still has an answer, and the stock returns as a movement rather
     * than by unwinding the original one.
     *
     * Quietly, like the posting it undoes: a document that cannot be voided
     * because the chart of accounts is half configured is a worse outcome than
     * books that need a correction.
     */
    protected function undoIssue(Document $document, User $user): void
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null || ! $document->type->affectsCustomerAccount()) {
            return;
        }

        $events = app(RecordsBusinessEvents::class);

        $events->recordQuietly(function () use ($document, $company, $user) {
            $ledger = app(Ledger::class);

            if ($entry = $ledger->entryFor($company, $document)) {
                $ledger->reverse($entry, $user, 'Annulation '.($document->number ?? ''));
            }

            return null;
        });

        $events->recordQuietly(
            fn () => app(StockLedger::class)->reverseSale($document, $company, $user)
        );
    }
}
