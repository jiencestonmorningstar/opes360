<?php

namespace App\Services\Insurance;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyInvoice;
use App\Models\PolicyCommission;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Placing cover, collecting the premium, and being paid for the placing.
 *
 * Deliberately a thin layer over the platform. The premium invoice is drafted
 * here the way ServiceBilling drafts a visit's bill — an ordinary `documents`
 * row with the shared VAT rules, linked back and never copied — and the
 * commission is a receivable against the insurer contact, invoiced through
 * the same path. Nothing in this class renders, numbers or accounts for
 * anything of its own.
 */
class Policies
{
    /**
     * @param  array{policy_number?: ?string, holder_contact_id: string, insurer_contact_id?: ?string,
     *               product_line?: string, premium?: ?float, currency?: ?string,
     *               commission_percent?: ?float, covers_from: string, covers_to?: ?string,
     *               renewal_type?: string, renewal_term_months?: ?int, notice_period_days?: ?int,
     *               owner_id?: ?int, notes?: ?string}  $data
     */
    public function place(array $data, ?User $actor = null): InsurancePolicy
    {
        $company = $this->company();

        $from = Carbon::parse($data['covers_from']);
        $to = isset($data['covers_to']) && $data['covers_to'] !== null
            ? Carbon::parse($data['covers_to'])
            : null;

        if ($to !== null && $to->lt($from)) {
            throw new RuntimeException(
                'A policy cannot stop covering before it starts. Check the cover dates.'
            );
        }

        if (($data['holder_contact_id'] ?? null) === null) {
            throw new RuntimeException(
                'A policy needs a policyholder — pick the client it covers.'
            );
        }

        /*
         * The same trap ContractLifecycle closes: a policy that renews itself
         * with no notice period gives nobody a date to be warned about, so it
         * rolls the client's premium over in silence forever. Refused at the
         * point of entry, where the schedule is still in front of somebody.
         */
        if (($data['renewal_type'] ?? 'manual') === 'auto'
            && ($data['notice_period_days'] ?? null) === null
            && $to !== null) {
            throw new RuntimeException(
                'An automatically renewing policy needs a notice period, otherwise nobody can '.
                'be told in time to rebroke or decline it.'
            );
        }

        $policy = InsurancePolicy::create([
            'company_id' => $company->id,
            'policy_number' => $data['policy_number'] ?? null,
            'holder_contact_id' => $data['holder_contact_id'],
            'insurer_contact_id' => $data['insurer_contact_id'] ?? null,
            'product_line' => $data['product_line'] ?? 'other',
            'premium' => $data['premium'] ?? null,
            'currency' => $data['currency'] ?? $company->currency,
            'commission_percent' => $data['commission_percent'] ?? null,
            'covers_from' => $from->toDateString(),
            'covers_to' => $to?->toDateString(),
            'renewal_type' => $data['renewal_type'] ?? 'manual',
            'renewal_term_months' => $data['renewal_term_months'] ?? null,
            'notice_period_days' => $data['notice_period_days'] ?? null,
            // Set here rather than left to the column default: Eloquent does
            // not read defaults back after an insert.
            'status' => 'draft',
            'owner_id' => $data['owner_id'] ?? $actor?->id,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $policy->emitDomainEvent('insurance.policy.placed', [
            'policy_id' => $policy->id,
            'holder_id' => $policy->holder_contact_id,
            'product_line' => $policy->product_line,
        ]);

        return $policy;
    }

    /** Cover is bound: the insurer has gone on risk. */
    public function bind(InsurancePolicy $policy, ?User $actor = null): InsurancePolicy
    {
        if ($policy->status === 'cancelled') {
            throw new RuntimeException(
                'This policy was cancelled. Place new cover rather than reviving it.'
            );
        }

        if ($policy->isActive()) {
            return $policy;
        }

        $policy->forceFill(['status' => 'active'])->save();

        $policy->emitDomainEvent('insurance.policy.bound', [
            'policy_id' => $policy->id,
            'covers_to' => $policy->covers_to?->toDateString(),
            'notice_by' => $policy->notice_by?->toDateString(),
        ]);

        return $policy;
    }

    public function cancel(InsurancePolicy $policy, array $data = [], ?User $actor = null): InsurancePolicy
    {
        if ($policy->status !== 'active') {
            throw new RuntimeException(
                'Only cover that is in force can be cancelled. This policy is '.$policy->status.'.'
            );
        }

        $policy->forceFill([
            'status' => 'cancelled',
            'cancelled_on' => isset($data['on'])
                ? Carbon::parse($data['on'])->toDateString()
                : Carbon::today()->toDateString(),
            'cancellation_reason' => $data['reason'] ?? null,
        ])->save();

        $policy->emitDomainEvent('insurance.policy.cancelled', [
            'policy_id' => $policy->id,
            'reason' => $policy->cancellation_reason,
        ]);

        return $policy;
    }

    /**
     * Draft the premium invoice — an ordinary sales invoice to the holder,
     * linked back to the policy.
     *
     * Draft, not issued: a broker checks a premium note against the schedule
     * before it goes out, exactly as ServiceBilling reasons about a visit.
     * Issue it from Sales, where issuing lives.
     */
    public function invoicePremium(InsurancePolicy $policy, User $by, ?float $amount = null): Document
    {
        if ($policy->holder_contact_id === null) {
            throw new RuntimeException(
                'This policy has no policyholder on record, so there is nobody to invoice.'
            );
        }

        $amount ??= $policy->premium !== null ? (float) $policy->premium : null;

        if ($amount === null || $amount <= 0) {
            throw new RuntimeException(
                'There is no premium on this policy to invoice. Record the premium first, or give an amount.'
            );
        }

        return DB::transaction(function () use ($policy, $by, $amount) {
            $company = $this->company();

            $cover = $policy->covers_from->toFormattedDateString()
                .' to '.($policy->covers_to?->toFormattedDateString() ?? 'open');

            $lines = [[
                'description' => sprintf(
                    '%s insurance premium — %s, cover %s',
                    $policy->productLineLabel(),
                    $policy->policy_number ?? 'policy pending number',
                    $cover,
                ),
                'quantity' => 1.0,
                'unit' => 'unit',
                'unit_price' => $amount,
            ]];

            $vat = Vat::forCompany($company, $lines);

            $invoice = Document::create([
                'company_id' => $policy->company_id,
                'type' => DocumentType::Invoice,
                'contact_id' => $policy->holder_contact_id,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $policy->currency ?? $company->currency,
                'subtotal' => $vat['subtotal'],
                'discount_total' => $vat['discount_total'],
                'tax_total' => $vat['tax_total'],
                'total' => $vat['total'],
                'amount_paid' => 0,
                'balance' => $vat['total'],
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

            // The link, never a copy. Dunning, receipts and aging all happen
            // to the document; this row only remembers whose premium it is.
            InsurancePolicyInvoice::create([
                'company_id' => $policy->company_id,
                'insurance_policy_id' => $policy->id,
                'document_id' => $invoice->id,
                'created_by' => $by->id,
            ]);

            $policy->emitDomainEvent('insurance.premium.invoiced', [
                'policy_id' => $policy->id,
                'document_id' => $invoice->id,
                'total' => (float) $invoice->total,
            ]);

            return $invoice->refresh();
        });
    }

    /**
     * Record what the insurer owes for placing this policy.
     *
     * Defaults to the agreed percentage of the premium; an explicit amount
     * wins, because agency terms have exceptions the percentage cannot know.
     */
    public function recordCommission(InsurancePolicy $policy, ?float $amount = null, ?User $actor = null): PolicyCommission
    {
        if ($policy->insurer_contact_id === null) {
            throw new RuntimeException(
                'This policy has no insurer on record, so there is nobody who owes commission.'
            );
        }

        if ($amount === null) {
            if ($policy->premium === null || $policy->commission_percent === null) {
                throw new RuntimeException(
                    'No commission terms on this policy — record the premium and the agreed '.
                    'percentage, or give the amount directly.'
                );
            }

            $amount = round((float) $policy->premium * (float) $policy->commission_percent / 100, 2);
        }

        if ($amount <= 0) {
            throw new RuntimeException('A commission has to be an amount above zero.');
        }

        return PolicyCommission::create([
            'company_id' => $policy->company_id,
            'insurance_policy_id' => $policy->id,
            'insurer_contact_id' => $policy->insurer_contact_id,
            'amount' => $amount,
            'currency' => $policy->currency,
            'earned_on' => Carbon::today()->toDateString(),
            'description' => $policy->productLineLabel().' — '.($policy->policy_number ?? 'policy'),
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * Bill an earned commission to the insurer — an ordinary invoice, so the
     * receivable ages and dunns like any other money the business is owed.
     */
    public function invoiceCommission(PolicyCommission $commission, User $by): Document
    {
        if ($commission->isInvoiced()) {
            throw new RuntimeException(
                'This commission is already on an invoice. Credit that one rather than raising a second.'
            );
        }

        return DB::transaction(function () use ($commission, $by) {
            $company = $this->company();

            $lines = [[
                'description' => 'Brokerage commission — '.($commission->description ?? 'placed policy'),
                'quantity' => 1.0,
                'unit' => 'unit',
                'unit_price' => (float) $commission->amount,
            ]];

            $vat = Vat::forCompany($company, $lines);

            $invoice = Document::create([
                'company_id' => $commission->company_id,
                'type' => DocumentType::Invoice,
                'contact_id' => $commission->insurer_contact_id,
                'status' => DocumentStatus::Draft,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $commission->currency ?? $company->currency,
                'subtotal' => $vat['subtotal'],
                'discount_total' => $vat['discount_total'],
                'tax_total' => $vat['tax_total'],
                'total' => $vat['total'],
                'amount_paid' => 0,
                'balance' => $vat['total'],
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

            $commission->forceFill(['document_id' => $invoice->id])->save();

            return $invoice->refresh();
        });
    }

    protected function company(): Company
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('No company is selected, so there is nothing to place cover for.');
        }

        return $company;
    }
}
