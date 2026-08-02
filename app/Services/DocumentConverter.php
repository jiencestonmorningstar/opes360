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

        return array_key_exists($document->type->value, self::TARGETS);
    }

    public function targetType(Document $document): ?DocumentType
    {
        return self::TARGETS[$document->type->value] ?? null;
    }

    public function convert(Document $source, User $user): Document
    {
        if (! $this->canConvert($source)) {
            throw new RuntimeException('This document cannot be converted.');
        }

        $target = $this->targetType($source);

        return DB::transaction(function () use ($source, $target, $user) {
            $source->loadMissing('lines');

            $copy = Document::create([
                'type' => $target,
                'contact_id' => $source->contact_id,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays($source->contact?->payment_terms_days ?? 14)->toDateString(),
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

            // The customer no longer owes this amount.
            if ($document->contact && $document->type->isReceivable()) {
                $document->contact->decrement('balance', (float) $document->total);
            }

            $this->undoIssue($document, $user);

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

        if ($company === null || ! $document->type->isReceivable()) {
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
