<?php

namespace App\Services\Service;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\DocumentIssuer;
use App\Support\Vat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning a finished visit into a bill.
 *
 * The bill is an ordinary sales invoice in the `documents` table, drafted with
 * the same VAT rules and issued through the same `DocumentIssuer` as every
 * other invoice in the product. Service management does not render, number,
 * print or account for anything: it works out *what* is chargeable and hands
 * it over. The job then holds `document_id` — a link, never a copy — so the
 * money is only ever stated once, in accounts receivable.
 *
 * There is one thing worth challenging here, and it is recorded rather than
 * hidden: the lines are assembled and written in this class, the way
 * `RecurringInvoices` and the sales screen each assemble theirs. That is the
 * third such assembly in the product. It should become one `InvoiceDrafter`
 * that all three call — see the handoff note — but extracting it means editing
 * two files this phase does not own.
 */
class ServiceBilling
{
    public function __construct(protected DocumentIssuer $issuer) {}

    /**
     * Draft the invoice for one completed visit.
     *
     * Draft, not issued. Somebody looks at a service invoice before it goes
     * out — a goodwill hour gets struck off, a part turns out to be under
     * warranty — and a module that issued automatically would be sending
     * arguments to customers.
     */
    public function draft(ServiceJob $job, User $by): Document
    {
        $this->refuseIfNotBillable($job);

        return DB::transaction(function () use ($job, $by) {
            $entries = $job->timeEntries()->billable()->unlocked()->get();
            $parts = $job->parts()->where('is_billable', true)->get();

            $lines = [];

            foreach ($entries as $entry) {
                $lines[] = [
                    'description' => trim(sprintf(
                        '%s — labour%s',
                        $job->ticket?->subject ?? 'Service visit',
                        $entry->notes ? ' ('.$entry->notes.')' : '',
                    )),
                    'quantity' => (float) $entry->hours,
                    'unit' => 'hour',
                    'unit_price' => (float) ($entry->hourly_rate ?? 0),
                ];
            }

            foreach ($parts as $part) {
                $lines[] = [
                    'description' => $part->label(),
                    'quantity' => (float) $part->quantity,
                    'unit' => $part->unit ?: 'unit',
                    'unit_price' => (float) $part->unit_price,
                ];
            }

            if ($lines === []) {
                throw new RuntimeException(
                    "There is nothing chargeable on {$job->reference}: no billable hours and no parts."
                );
            }

            $company = $job->company;
            $vat = Vat::forCompany($company, $lines);

            $invoice = Document::create([
                'company_id' => $job->company_id,
                'type' => DocumentType::Invoice,
                'contact_id' => $job->ticket?->contact_id,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $company->currency,
                'subtotal' => $vat['subtotal'],
                'discount_total' => $vat['discount_total'],
                'tax_total' => $vat['tax_total'],
                'total' => $vat['total'],
                'amount_paid' => 0,
                'balance' => $vat['total'],
                'notes' => $job->on_site_notes,
                'project_id' => $job->ticket?->project_id,
                'created_by' => $by->id,
            ]);

            foreach ($lines as $index => $line) {
                DocumentLine::create([
                    'document_id' => $invoice->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $vat['lines'][$index]['tax'] ?? 0.0,
                    'line_total' => $vat['lines'][$index]['net'] ?? 0.0,
                    'sort_order' => $index,
                ]);
            }

            /*
             * Once billed, the hours are history — the same rule the projects
             * timesheet already enforces, and the reason this reuses that
             * table rather than keeping its own. An invoice already sent must
             * not be able to disagree with the timesheet underneath it.
             */
            foreach ($entries as $entry) {
                $entry->forceFill(['locked_at' => now()])->save();
            }

            $job->forceFill(['document_id' => $invoice->id])->save();

            $job->emitDomainEvent('service.job.billed', [
                'job_id' => $job->id,
                'document_id' => $invoice->id,
                'total' => (float) $invoice->total,
            ]);

            return $invoice->refresh();
        });
    }

    /** Draft it and put it out, for a desk that has decided it trusts the arithmetic. */
    public function issue(ServiceJob $job, User $by): Document
    {
        return $this->issuer->issue($this->draft($job, $by), $by);
    }

    protected function refuseIfNotBillable(ServiceJob $job): void
    {
        if (! $job->isComplete()) {
            throw new RuntimeException(
                "{$job->reference} has not been completed yet, so there is nothing to bill for."
            );
        }

        if (! $job->is_billable) {
            throw new RuntimeException(
                "{$job->reference} was recorded as non-chargeable — a warranty or goodwill visit."
            );
        }

        if ($job->isBilled()) {
            throw new RuntimeException(
                "{$job->reference} is already on an invoice. Credit that one rather than raising a second."
            );
        }

        /*
         * Where a business has put service invoicing behind an approval path,
         * the engine's answer is the gate. Nothing service-specific about the
         * check: it reads the one workflow engine, like everything else.
         */
        if ($job->isAwaitingApproval()) {
            throw new RuntimeException(
                "{$job->reference} is still waiting on approval and cannot be billed yet."
            );
        }
    }
}
