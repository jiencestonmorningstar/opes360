<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Models\VipMembership;
use App\Models\VipTier;
use App\Support\CurrentCompany;
use App\Support\Vat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Selling, cancelling and lapsing VIP memberships.
 *
 * A membership is sold the same way any other paid item is: through a real
 * invoice raised by the ordinary sales path, so the fee shows up in revenue,
 * in the customer's balance and in the books like anything else the business
 * sells. Nothing here invents a side channel for the money.
 */
class VipMemberships
{
    public function __construct(protected DocumentIssuer $issuer) {}

    public function sell(Contact $contact, VipTier $tier, User $actor): VipMembership
    {
        if (! $tier->is_active) {
            throw new RuntimeException("The {$tier->name} tier is not currently on sale.");
        }

        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot sell a membership without a current company.');
        }

        return DB::transaction(function () use ($contact, $tier, $actor, $company) {
            $current = VipMembership::query()
                ->where('contact_id', $contact->id)
                ->live()
                ->orderByDesc('ends_on')
                ->first();

            // Buying again while a term is still running extends it rather
            // than restarting it — a member who renews early keeps the days
            // they already paid for instead of losing them to the new term's
            // start date.
            $startsOn = $current !== null
                ? $current->ends_on->copy()->addDay()
                : now()->startOfDay();

            if ($current !== null) {
                $current->forceFill(['status' => VipMembership::EXPIRED])->save();
            }

            $endsOn = $startsOn->copy()->addMonths($tier->period_months)->subDay();

            $document = $this->invoiceFor($contact, $tier, $company, $actor);

            return VipMembership::create([
                'contact_id' => $contact->id,
                'vip_tier_id' => $tier->id,
                // Copied rather than read live through the tier — see the
                // migration. Raising Gold's rate next year must not rewrite
                // what this member was actually sold today.
                'tier_name' => $tier->name,
                'discount_percent' => $tier->discount_percent,
                'price_paid' => $tier->price,
                'currency' => $tier->currency,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'status' => VipMembership::ACTIVE,
                'document_id' => $document->id,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * The rate to apply to this contact's invoices today. A contact can hold
     * more than one live membership only in edge cases (e.g. a manual backdate),
     * so the highest discount wins rather than the most recent.
     */
    public function discountFor(?Contact $contact): float
    {
        if ($contact === null) {
            return 0.0;
        }

        $membership = VipMembership::query()
            ->where('contact_id', $contact->id)
            ->live()
            ->orderByDesc('discount_percent')
            ->first();

        return $membership?->effectiveDiscount() ?? 0.0;
    }

    public function cancel(VipMembership $membership, string $reason, User $actor): VipMembership
    {
        $membership->forceFill([
            'status' => VipMembership::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_reason' => $reason,
        ])->save();

        return $membership;
    }

    /**
     * Tidying, not enforcement: `isActive()` already refuses a discount to a
     * membership past its `ends_on`, so a sweep that has not run yet cannot
     * hand one out. This only keeps the status column — the convenience field
     * lists and filters read — honest overnight, across every company, since
     * it runs from a console command with no tenant in scope.
     */
    public function expireLapsed(): int
    {
        return VipMembership::withoutGlobalScopes()
            ->where('status', VipMembership::ACTIVE)
            ->whereDate('ends_on', '<', now())
            ->update(['status' => VipMembership::EXPIRED]);
    }

    protected function invoiceFor(Contact $contact, VipTier $tier, Company $company, User $actor): Document
    {
        $lines = [[
            'quantity' => 1,
            'unit_price' => $tier->price,
        ]];

        // No discount on the membership fee itself — the discount a tier
        // grants applies to what a member buys afterwards, not to the price
        // of the membership.
        $vat = Vat::forCompany($company, $lines);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays($contact->payment_terms_days ?? 14)->toDateString(),
            'currency' => $company->currency,
            'subtotal' => $vat['subtotal'],
            'discount_total' => $vat['discount_total'],
            'tax_total' => $vat['tax_total'],
            'total' => $vat['total'],
            'amount_paid' => 0,
            'balance' => $vat['total'],
            'created_by' => $actor->id,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => "VIP membership — {$tier->name} ({$tier->period_months} months)",
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => $tier->price,
            'tax_amount' => $vat['lines'][0]['tax'] ?? 0.0,
            'line_total' => $vat['lines'][0]['net'] ?? 0.0,
            'sort_order' => 0,
        ]);

        return $this->issuer->issue($document->fresh(), $actor);
    }
}
