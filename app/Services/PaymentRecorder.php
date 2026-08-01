<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Models\Device;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VerificationToken;
use App\Notifications\PaymentReceivedNotification;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Support\CurrentCompany;
use App\Support\NotifyCompany;
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
    public function __construct(protected DocumentNumbers $numbers, protected LoyaltyLedger $loyalty) {}

    /**
     * @param  string|null  $receiptNumber  A receipt number a device already
     *                                      printed offline. Honoured only after
     *                                      being checked against that device's
     *                                      lease — see $device.
     * @param  Device|null  $device  The device that assigned $receiptNumber.
     * @param  string|null  $paymentId  The id the device generated, so a replay
     *                                  of the same envelope is recognised
     *                                  rather than taking the money twice.
     */
    public function record(
        Document $document,
        User $cashier,
        float $amount,
        PaymentMethod $method,
        ?string $reference = null,
        string $receiptFormat = 'thermal80',
        ?string $receiptNumber = null,
        ?Device $device = null,
        ?string $paymentId = null,
    ): Payment {
        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($document, $cashier, $amount, $method, $reference, $receiptFormat, $receiptNumber, $device, $paymentId) {
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

            $payment = new Payment;

            // A payment created offline keeps the id the device gave it, so a
            // replayed envelope is recognised as the same payment rather than
            // taking the money a second time. Assigned on the instance because
            // the key is guarded against mass assignment, as it should be.
            if ($paymentId !== null && $paymentId !== '') {
                $payment->id = $paymentId;
            }

            $payment->forceFill([
                'contact_id' => $document->contact_id,
                'method' => $method,
                'amount' => $amount,
                'currency' => $document->currency,
                'reference' => $reference,
                'received_at' => now(),
                'received_by' => $cashier->id,
                'device_id' => $device?->id,
            ])->save();

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'document_id' => $document->id,
                'amount' => $amount,
            ]);

            // Best-effort: a mail/notification failure must never unwind a
            // payment that has already moved money.
            try {
                NotifyCompany::about($document->company, 'payments.view', new PaymentReceivedNotification($payment));
            } catch (\Throwable) {
            }

            // Earning is a no-op when the program is off or there is no
            // contact to credit (a walk-in sale with no customer record).
            if ($document->contact) {
                $this->loyalty->earn($document->contact, $document->company, $amount, Payment::class, $payment->id, $cashier);
            }

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
                // A receipt printed offline keeps the number on the customer's
                // copy. Claiming verifies it against the device's own lease and
                // throws if it is not there, which rejects the whole payment
                // rather than issuing a second receipt with a different number.
                'number' => $this->receiptNumber($receiptNumber, $device),
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
                'content_hash' => hash('sha256', $receipt->canonicalPayload()),
                'verification_token_id' => VerificationToken::create([
                    'token' => VerificationToken::newToken(),
                    'subject_type' => Receipt::class,
                    'subject_id' => $receipt->id,
                ])->id,
            ])->saveQuietly();

            // Keep the cached rollup the customer list sorts by in step.
            $document->contact?->decrement('balance', $amount);

            // Settlement enters the books: the money moves off the customer's
            // account and into the till or the bank. Wrapped for the same
            // reason as the sale — bookkeeping must never be why a payment at
            // the counter fails.
            $events = app(RecordsBusinessEvents::class);
            $company = app(CurrentCompany::class)->get();

            if ($company !== null) {
                $events->recordQuietly(fn () => $events->recordPayment($payment, $company, $cashier));
            }

            return $payment;
        });
    }

    protected function receiptNumber(?string $supplied, ?Device $device): string
    {
        if ($supplied === null || $supplied === '') {
            return $this->numbers->nextReceipt();
        }

        if ($device === null) {
            throw new RuntimeException('A receipt number was supplied without an identified device.');
        }

        $this->numbers->claim($supplied, 'receipt', $device);

        return $supplied;
    }
}
