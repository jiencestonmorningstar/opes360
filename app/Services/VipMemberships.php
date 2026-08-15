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
use App\Models\VerificationToken;
use App\Models\VipTier;
use App\Support\CurrentCompany;
use App\Support\Vat;
use App\Support\WebhookEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    public function __construct(
        protected DocumentIssuer $issuer,
        protected WebhookDispatcher $webhooks,
    ) {}

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
            /*
             * Locked, for the same reason PaymentRecorder locks its document.
             *
             * Two "Buy" requests for the same customer arriving together would
             * otherwise both read no live membership, both pass the check, and
             * both raise an invoice — the customer charged twice for one term.
             * The row lock makes the second wait until the first has committed,
             * so it sees the membership the first created and extends it.
             *
             * Locking a customer that has no membership yet locks nothing, so
             * the very first concurrent pair can still both proceed. That is
             * the residual case a unique constraint would close, and it is
             * noted rather than papered over: the window is a few milliseconds
             * and the outcome is two terms that run consecutively rather than
             * money taken for nothing.
             */
            $current = VipMembership::query()
                ->where('contact_id', $contact->id)
                ->live()
                ->orderByDesc('ends_on')
                ->lockForUpdate()
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

            /*
             * NoOverflow, because the plain version is surprising at month end.
             * A one-month term sold on 31 January would otherwise run to 2
             * March — Carbon rolls 31 February forward into the next month —
             * so the member gets 31 days and the anniversary drifts. Clamping
             * to the last day of the target month is what a subscription is
             * understood to mean.
             */
            $endsOn = $startsOn->copy()->addMonthsNoOverflow($tier->period_months)->subDay();

            $document = $this->invoiceFor($contact, $tier, $company, $actor);

            $membership = VipMembership::create([
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

            $this->issueCard($membership);

            $this->webhooks->send(WebhookEvents::VIP_SOLD, $this->payload($membership), $company);

            return $membership;
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

        $this->webhooks->send(
            WebhookEvents::VIP_CANCELLED,
            $this->payload($membership) + ['reason' => $reason],
        );

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
        /*
         * Read the rows before updating them rather than issuing one bulk
         * UPDATE. A subscriber wanting to win a lapsed member back needs to be
         * told which membership lapsed, and a mass update knows only how many
         * did. The volume is one night's expiries, so the extra reads cost
         * nothing worth saving.
         */
        $lapsed = VipMembership::withoutGlobalScopes()
            ->with('company')
            ->where('status', VipMembership::ACTIVE)
            ->whereDate('ends_on', '<', now())
            ->get();

        foreach ($lapsed as $membership) {
            $membership->forceFill(['status' => VipMembership::EXPIRED])->save();

            // The company is passed explicitly: this runs from a console
            // command, where there is no current company to fall back on.
            $this->webhooks->send(
                WebhookEvents::VIP_EXPIRED,
                $this->payload($membership),
                $membership->company,
            );
        }

        return $lapsed->count();
    }

    /**
     * A card number, and the token its QR resolves to.
     *
     * The same shape as the loyalty card rather than a second scheme: a prefix
     * so a number read down a phone line is recognisable as what it is, and a
     * verification token so somebody holding a printed card can check it
     * against the business without an account. A member showing a card at the
     * door is the case this exists for, and the person on the door has no way
     * to look them up otherwise.
     */
    protected function issueCard(VipMembership $membership): void
    {
        $token = VerificationToken::create([
            'company_id' => $membership->company_id,
            'token' => VerificationToken::newToken(),
            'subject_type' => VipMembership::class,
            'subject_id' => $membership->id,
        ]);

        do {
            $number = 'VIP-'.Str::upper(Str::random(8));
        } while (VipMembership::withoutGlobalScopes()
            ->where('company_id', $membership->company_id)
            ->where('card_number', $number)
            ->exists());

        $membership->forceFill([
            'card_number' => $number,
            'verification_token_id' => $token->id,
        ])->save();
    }

    /**
     * What a subscriber is told about a membership.
     *
     * The terms are the membership's own rather than its tier's — a tier
     * repriced since the sale must not change what this member is reported as
     * holding.
     *
     * @return array<string, mixed>
     */
    protected function payload(VipMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'contact_id' => $membership->contact_id,
            'tier_name' => $membership->tier_name,
            'discount_percent' => (float) $membership->discount_percent,
            'price_paid' => (float) $membership->price_paid,
            'currency' => $membership->currency,
            'starts_on' => $membership->starts_on?->toDateString(),
            'ends_on' => $membership->ends_on?->toDateString(),
            'status' => $membership->status,
            'document_id' => $membership->document_id,
        ];
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
