<?php

namespace App\Services\Insurance;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\InsuranceEndorsement;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyInvoice;
use App\Models\InsurancePolicyRenewal;
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

    /**
     * Renew the cover: a new term, a possibly new premium, and the history
     * kept — the ContractLifecycle::renew pattern. The new dates and the
     * renewal row are written together or not at all; a policy sitting on a
     * date with nothing saying how it got there is exactly the state the
     * watch cannot answer for.
     *
     * @param  array{new_covers_to?: ?string, new_premium?: ?float, method?: string,
     *               notes?: ?string, on?: ?string}  $data
     */
    public function renew(InsurancePolicy $policy, array $data = [], ?User $actor = null): InsurancePolicyRenewal
    {
        if ($policy->status !== 'active') {
            throw new RuntimeException(
                'Only cover that is in force can be renewed. This policy is '.$policy->status.'.'
            );
        }

        if ($policy->covers_to === null) {
            throw new RuntimeException(
                'This is open cover with no end date, so there is no term to renew. It runs until it is cancelled.'
            );
        }

        // The new term starts the day after the old one ends: no gap the
        // client is bare through, no overlap they are billed twice for.
        $newFrom = $policy->covers_to->copy()->addDay();

        $newTo = isset($data['new_covers_to']) && $data['new_covers_to'] !== null
            ? Carbon::parse($data['new_covers_to'])
            : ($policy->renewal_term_months !== null
                ? $newFrom->copy()->addMonths($policy->renewal_term_months)
                : null);

        if ($newTo === null) {
            throw new RuntimeException(
                'This policy has no agreed renewal term, so a new end date has to be given.'
            );
        }

        if ($newTo->lte($policy->covers_to)) {
            throw new RuntimeException(
                'A renewal has to extend the cover. '.
                $newTo->toDateString().' is not after '.$policy->covers_to->toDateString().'.'
            );
        }

        return DB::transaction(function () use ($policy, $newFrom, $newTo, $data, $actor) {
            $renewal = InsurancePolicyRenewal::create([
                'company_id' => $policy->company_id,
                'insurance_policy_id' => $policy->id,
                'previous_covers_from' => $policy->covers_from?->toDateString(),
                'previous_covers_to' => $policy->covers_to->toDateString(),
                'new_covers_from' => $newFrom->toDateString(),
                'new_covers_to' => $newTo->toDateString(),
                'previous_premium' => $policy->premium,
                'new_premium' => $data['new_premium'] ?? $policy->premium,
                'method' => $data['method'] ?? 'negotiated',
                'renewed_on' => isset($data['on'])
                    ? Carbon::parse($data['on'])->toDateString()
                    : Carbon::today()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $policy->forceFill([
                'covers_from' => $newFrom->toDateString(),
                'covers_to' => $newTo->toDateString(),
                'premium' => $data['new_premium'] ?? $policy->premium,
                'status' => 'active',
            ]);

            // notice_by follows from the new end date, and the model's saving
            // hook is what recomputes it — one writer for one derived value.
            $policy->save();

            $policy->emitDomainEvent('insurance.policy.renewed', [
                'policy_id' => $policy->id,
                'new_covers_to' => $newTo->toDateString(),
                'method' => $renewal->method,
            ]);

            return $renewal;
        });
    }

    /**
     * Change the cover mid-term and record what changed.
     *
     * The endorsement row is the record; the money is an ordinary sales
     * document — a debit note when the client owes an additional premium, a
     * credit note when premium comes back — drafted, never issued, because a
     * broker checks an adjustment against the insurer's endorsement schedule
     * before it goes out. Issue it from Sales, where issuing lives.
     *
     * @param  array{description: string, effective_on?: ?string, new_premium?: ?float,
     *               premium_delta?: ?float}  $data
     */
    public function endorse(InsurancePolicy $policy, array $data, ?User $actor = null): InsuranceEndorsement
    {
        if ($policy->status !== 'active') {
            throw new RuntimeException(
                'Only cover that is in force can be endorsed. This policy is '.$policy->status.'.'
            );
        }

        $description = trim($data['description'] ?? '');

        if ($description === '') {
            throw new RuntimeException(
                'An endorsement has to say what changed — that record is its whole point.'
            );
        }

        $newPremium = $data['new_premium'] ?? null;

        // An explicit delta wins; otherwise it follows from the premiums.
        $delta = $data['premium_delta']
            ?? ($newPremium !== null && $policy->premium !== null
                ? round((float) $newPremium - (float) $policy->premium, 2)
                : 0.0);

        return DB::transaction(function () use ($policy, $description, $newPremium, $delta, $data, $actor) {
            $note = null;

            if (abs($delta) > 0.005) {
                $note = $this->draftAdjustmentNote(
                    $policy,
                    $delta > 0 ? DocumentType::DebitNote : DocumentType::CreditNote,
                    sprintf(
                        '%s — endorsement: %s',
                        $delta > 0 ? 'Additional premium' : 'Return premium',
                        $description,
                    ),
                    abs($delta),
                    $actor,
                );
            }

            $endorsement = InsuranceEndorsement::create([
                'company_id' => $policy->company_id,
                'insurance_policy_id' => $policy->id,
                'effective_on' => isset($data['effective_on'])
                    ? Carbon::parse($data['effective_on'])->toDateString()
                    : Carbon::today()->toDateString(),
                'description' => $description,
                'previous_premium' => $policy->premium,
                'new_premium' => $newPremium ?? $policy->premium,
                'premium_delta' => $delta,
                'document_id' => $note?->id,
                'created_by' => $actor?->id,
            ]);

            if ($newPremium !== null) {
                $policy->forceFill(['premium' => $newPremium])->save();
            }

            $policy->emitDomainEvent('insurance.policy.endorsed', [
                'policy_id' => $policy->id,
                'endorsement_id' => $endorsement->id,
                'premium_delta' => $delta,
            ]);

            return $endorsement;
        });
    }

    /**
     * What the client is owed back if cover stops on `$on` — the unexpired
     * pro-rata of the premium, straight-line over the term in days.
     */
    public function returnPremium(InsurancePolicy $policy, ?Carbon $on = null): float
    {
        if ($policy->premium === null
            || $policy->covers_from === null
            || $policy->covers_to === null) {
            return 0.0;
        }

        $on = ($on ?? Carbon::today())->copy()->startOfDay();

        $total = (int) $policy->covers_from->copy()->startOfDay()
            ->diffInDays($policy->covers_to->copy()->startOfDay());

        if ($total <= 0) {
            return 0.0;
        }

        $remaining = (int) $on->diffInDays($policy->covers_to->copy()->startOfDay(), false);
        $remaining = max(0, min($remaining, $total));

        return round((float) $policy->premium * $remaining / $total, 2);
    }

    public function cancel(InsurancePolicy $policy, array $data = [], ?User $actor = null): InsurancePolicy
    {
        if ($policy->status !== 'active') {
            throw new RuntimeException(
                'Only cover that is in force can be cancelled. This policy is '.$policy->status.'.'
            );
        }

        $cancelledOn = isset($data['on'])
            ? Carbon::parse($data['on'])
            : Carbon::today();

        return DB::transaction(function () use ($policy, $data, $cancelledOn, $actor) {
            $policy->forceFill([
                'status' => 'cancelled',
                'cancelled_on' => $cancelledOn->toDateString(),
                'cancellation_reason' => $data['reason'] ?? null,
            ])->save();

            /*
             * The unexpired premium comes back through the ordinary path: a
             * credit note against the issued premium invoice. Only when one
             * was actually issued — a premium never billed has nothing in the
             * books to return, and drafting a note against nothing would put
             * a client in credit for money that never moved.
             */
            $return = $this->returnPremium($policy, $cancelledOn);
            $invoice = $this->latestIssuedPremiumInvoice($policy);
            $noteId = null;

            if ($return > 0.005 && $invoice !== null) {
                $note = $this->draftAdjustmentNote(
                    $policy,
                    DocumentType::CreditNote,
                    sprintf(
                        'Return premium — %s cancelled %s, unexpired pro-rata',
                        $policy->policy_number ?? 'policy',
                        $cancelledOn->toFormattedDateString(),
                    ),
                    min($return, (float) $invoice->total),
                    $actor,
                    parentDocumentId: $invoice->id,
                );

                $noteId = $note->id;
            }

            $policy->emitDomainEvent('insurance.policy.cancelled', [
                'policy_id' => $policy->id,
                'reason' => $policy->cancellation_reason,
                'return_premium' => $return,
                'credit_note_id' => $noteId,
            ]);

            return $policy;
        });
    }

    /**
     * Bill the premium in instalments: all N draft invoices generated up
     * front, due dates a month apart, so aging and dunning can see the whole
     * schedule from day one.
     *
     * Not RecurringInvoice on purpose: that models an open-ended rhythm that
     * bills forward as time passes. An instalment plan is a fixed N slices of
     * a known total tied to one cover period — the client agreed all N dates
     * when the policy was placed, and a schedule the collector cannot see in
     * full is a schedule nobody chases.
     *
     * @return array<int, Document>
     */
    public function invoicePremiumInstalments(
        InsurancePolicy $policy,
        User $by,
        int $count,
        ?Carbon $firstDueOn = null,
    ): array {
        if ($count < 2 || $count > 12) {
            throw new RuntimeException(
                'An instalment plan is between 2 and 12 instalments. For a single payment, invoice the premium directly.'
            );
        }

        if ($policy->holder_contact_id === null) {
            throw new RuntimeException(
                'This policy has no policyholder on record, so there is nobody to invoice.'
            );
        }

        $total = $policy->premium !== null ? (float) $policy->premium : 0.0;

        if ($total <= 0) {
            throw new RuntimeException(
                'There is no premium on this policy to spread over instalments. Record the premium first.'
            );
        }

        /*
         * Floor the slice at the currency's own precision and put the
         * rounding remainder on the first instalment, so the schedule sums
         * to the premium exactly. XAF and XOF have no minor unit — the same
         * fact Vat rounds by — so a franc premium splits into whole francs.
         */
        $decimals = in_array($policy->currency ?? $this->company()->currency, ['XAF', 'XOF'], true) ? 0 : 2;
        $factor = 10 ** $decimals;
        $slice = floor($total / $count * $factor) / $factor;
        $first = round($total - $slice * ($count - 1), $decimals);
        $firstDueOn = ($firstDueOn ?? Carbon::today()->addDays(30))->copy();

        return DB::transaction(function () use ($policy, $by, $count, $slice, $first, $firstDueOn) {
            $invoices = [];

            for ($i = 1; $i <= $count; $i++) {
                $invoices[] = $this->draftAdjustmentNote(
                    $policy,
                    DocumentType::Invoice,
                    sprintf(
                        '%s insurance premium — %s, instalment %d of %d',
                        $policy->productLineLabel(),
                        $policy->policy_number ?? 'policy pending number',
                        $i,
                        $count,
                    ),
                    $i === 1 ? $first : $slice,
                    $by,
                    dueOn: $firstDueOn->copy()->addMonthsNoOverflow($i - 1),
                );
            }

            $policy->emitDomainEvent('insurance.premium.invoiced', [
                'policy_id' => $policy->id,
                'instalments' => $count,
                'document_ids' => array_map(fn (Document $d) => $d->id, $invoices),
            ]);

            return $invoices;
        });
    }

    /** The most recent premium invoice that has actually been issued. */
    protected function latestIssuedPremiumInvoice(InsurancePolicy $policy): ?Document
    {
        return Document::query()
            ->whereIn('id', $policy->premiumInvoiceLinks()->pluck('document_id'))
            ->ofType(DocumentType::Invoice)
            ->where('status', DocumentStatus::Issued)
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * One drafting path for every premium-side document — invoice,
     * instalment, debit note, credit note — so the VAT treatment, the link
     * row and the draft-first rule can never drift between them.
     */
    protected function draftAdjustmentNote(
        InsurancePolicy $policy,
        DocumentType $type,
        string $description,
        float $amount,
        ?User $actor,
        ?Carbon $dueOn = null,
        ?string $parentDocumentId = null,
    ): Document {
        $company = $this->company();

        $lines = [[
            'description' => $description,
            'quantity' => 1.0,
            'unit' => 'unit',
            'unit_price' => $amount,
        ]];

        $vat = Vat::forCompany($company, $lines);

        $document = Document::create([
            'company_id' => $policy->company_id,
            'type' => $type,
            'contact_id' => $policy->holder_contact_id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            // A credit note is not owed on a date — it is money the business
            // already accepts it is not owed.
            'due_date' => $type === DocumentType::CreditNote
                ? null
                : ($dueOn ?? now()->addDays(30))->toDateString(),
            'currency' => $policy->currency ?? $company->currency,
            'subtotal' => $vat['subtotal'],
            'discount_total' => $vat['discount_total'],
            'tax_total' => $vat['tax_total'],
            'total' => $vat['total'],
            'amount_paid' => 0,
            'balance' => $vat['total'],
            'parent_document_id' => $parentDocumentId,
            'created_by' => $actor?->id,
        ]);

        foreach ($lines as $index => $line) {
            DocumentLine::create([
                'document_id' => $document->id,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_price' => $line['unit_price'],
                'tax_amount' => $vat['lines'][$index]['tax'] ?? 0.0,
                'line_total' => $vat['lines'][$index]['net'] ?? 0.0,
                'sort_order' => $index,
            ]);
        }

        // The link, never a copy — the policy screen lists everything billed
        // or credited under this cover through these rows.
        InsurancePolicyInvoice::create([
            'company_id' => $policy->company_id,
            'insurance_policy_id' => $policy->id,
            'document_id' => $document->id,
            'created_by' => $actor?->id,
        ]);

        return $document->refresh();
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
