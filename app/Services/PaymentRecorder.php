<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VerificationToken;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a payment against a document and issues its receipt in one
 * transaction: payment, allocation, document balance and status, receipt with a
 * final ledger number, receipt hash and verification token, and the contact's
 * cached balance. Nothing partial can be observed.
 */
class PaymentRecorder
{
    public function __construct(protected DocumentNumbers $numbers) {}

    public function record(
        Document $document,
        User $cashier,
        float $amount,
        PaymentMethod $method,
        ?string $reference = null,
        string $receiptFormat = 'thermal80',
    ): Payment {
        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($document, $cashier, $amount, $method, $reference, $receiptFormat) {
            // Re-read under a row lock: two cashiers recording against the same
            // invoice at once must not both pass the balance check on stale data.
            $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($document->status === DocumentStatus::Draft || $document->status === DocumentStatus::Void) {
                throw new RuntimeException('Payments can only be recorded against issued documents.');
            }

            // Overpayment is refused rather than silently absorbed. Genuine
            // customer credit is a Phase 7 feature (an unallocated payment), not
            // a negative balance on an invoice.
            if (round($amount, 2) > round((float) $document->balance, 2)) {
                throw new RuntimeException(sprintf(
                    'Payment of %.2f exceeds the outstanding balance of %.2f.',
                    $amount,
                    (float) $document->balance,
                ));
            }
            $payment = Payment::create([
                'contact_id' => $document->contact_id,
                'method' => $method,
                'amount' => $amount,
                'currency' => $document->currency,
                'reference' => $reference,
                'received_at' => now(),
                'received_by' => $cashier->id,
            ]);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'document_id' => $document->id,
                'amount' => $amount,
            ]);

            $paid = round((float) $document->amount_paid + $amount, 2);
            $balance = round((float) $document->total - $paid, 2);

            $document->forceFill([
                'amount_paid' => $paid,
                'balance' => $balance,
                'status' => $balance <= 0 ? DocumentStatus::Paid : DocumentStatus::Partial,
            ])->save();

            $receipt = Receipt::create([
                'payment_id' => $payment->id,
                'contact_id' => $document->contact_id,
                'number' => $this->numbers->nextReceipt(),
                'format' => $receiptFormat,
                'total' => $amount,
                'currency' => $document->currency,
                'status' => 'issued',
                'issued_at' => now(),
                'cashier_id' => $cashier->id,
            ]);

            // Same refresh-before-hash rule as documents: hash what the database
            // holds, not the raw pre-cast values.
            $receipt->refresh();
            $receipt->forceFill([
                'content_hash' => hash('sha256', json_encode([
                    'number' => $receipt->number,
                    'payment_id' => $receipt->payment_id,
                    'total' => (string) $receipt->total,
                    'currency' => $receipt->currency,
                    'issued_at' => $receipt->issued_at->toIso8601String(),
                ], JSON_THROW_ON_ERROR)),
                'verification_token_id' => VerificationToken::create([
                    'token' => VerificationToken::newToken(),
                    'subject_type' => Receipt::class,
                    'subject_id' => $receipt->id,
                ])->id,
            ])->saveQuietly();

            // Keep the cached rollup the customer list sorts by in step.
            $document->contact?->decrement('balance', $amount);

            return $payment;
        });
    }
}
