<?php

namespace App\Services\Partners;

use App\Models\CardIssuance;
use App\Models\Company;
use App\Models\PartnerClient;
use App\Models\PartnerCommission;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Notifications\PartnerCommissionEarnedNotification;
use Illuminate\Support\Facades\DB;

/**
 * The two things a partner earns from, and the one thing they are charged for.
 *
 * Kept out of the models because both sides cross tenants: a commission belongs
 * to the partner but is caused by a payment inside somebody else's company, and
 * a referral is written by a registration that has no current company at all.
 */
class PartnerProgramme
{
    /**
     * Resolve an invite string to the partner it belongs to.
     *
     * Accepts either a client's personal invite token or a partner's own code,
     * because both end up printed on things and people will type whichever one
     * is in front of them. Returns null rather than throwing: an unrecognised
     * ref in a URL must never stop somebody registering.
     *
     * @return array{partner: Company, client: ?PartnerClient}|null
     */
    public function resolveReferral(?string $ref): ?array
    {
        if ($ref === null || trim($ref) === '') {
            return null;
        }

        $ref = trim($ref);

        $client = PartnerClient::query()->acrossAllCompanies()
            ->where('invite_token', $ref)
            ->first();

        if ($client !== null) {
            $partner = Company::query()->find($client->company_id);

            return $partner === null ? null : ['partner' => $partner, 'client' => $client];
        }

        $partner = Company::query()
            ->where('partner_code', mb_strtoupper($ref))
            ->first();

        return $partner === null ? null : ['partner' => $partner, 'client' => null];
    }

    /**
     * Attribute a newly registered business to the partner that brought it in.
     *
     * Written once. A business already attributed is left alone — otherwise a
     * second partner sending the same person a link could take over a referral
     * that someone else earned, simply by being later.
     */
    public function attribute(Company $business, string $ref): bool
    {
        if ($business->referred_by_company_id !== null) {
            return false;
        }

        $resolved = $this->resolveReferral($ref);

        if ($resolved === null) {
            return false;
        }

        // A partner referring itself would earn commission on its own
        // subscription, which is not a referral programme, it is a discount.
        if ($resolved['partner']->id === $business->id) {
            return false;
        }

        return DB::transaction(function () use ($business, $resolved) {
            $business->forceFill([
                'referred_by_company_id' => $resolved['partner']->id,
                'referred_at' => now(),
            ])->save();

            $resolved['client']?->forceFill([
                'converted_company_id' => $business->id,
                'converted_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * Charge a partner for a card or letterhead they generated.
     *
     * The fee is copied onto the row from config at the moment of issue, so a
     * later price change cannot rewrite what a past month cost.
     */
    public function recordIssuance(
        Company $partner,
        string $asset,
        string $design,
        string $subjectName,
        ?PartnerClient $client = null,
        ?User $issuer = null,
    ): CardIssuance {
        return CardIssuance::create([
            'company_id' => $partner->id,
            'partner_client_id' => $client?->id,
            'asset' => in_array($asset, CardIssuance::ASSETS, true) ? $asset : 'card',
            'design' => $design,
            'subject_name' => $subjectName,
            'fee' => (int) config('opes.partners.card_fee'),
            'currency' => config('opes.partners.currency', 'XAF'),
            'issued_by' => $issuer?->id,
            'status' => 'billed',
        ]);
    }

    /**
     * Credit the referring partner their share of a settled subscription payment.
     *
     * Called from the biller once a payment is confirmed — never on a pending
     * one. Silently does nothing when there is no referrer, when the payment
     * did not succeed, or when this payment has already been credited: a
     * webhook and a manual status check both settling the same payment must not
     * pay a partner twice, which is what the unique constraint on
     * subscription_payment_id is there to guarantee.
     */
    public function creditCommission(SubscriptionPayment $payment): ?PartnerCommission
    {
        if (! $payment->isSuccessful()) {
            return null;
        }

        $business = Company::query()->find($payment->company_id);

        if ($business?->referred_by_company_id === null) {
            return null;
        }

        $partner = Company::query()->find($business->referred_by_company_id);

        if ($partner === null || $partner->trashed()) {
            return null;
        }

        $rate = (float) config('opes.partners.commission_rate');
        $base = (int) round((float) $payment->amount);
        $amount = (int) floor($base * $rate);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($partner, $business, $payment, $rate, $base, $amount) {
            $already = PartnerCommission::query()->acrossAllCompanies()
                ->where('subscription_payment_id', $payment->id)
                ->lockForUpdate()
                ->exists();

            if ($already) {
                return null;
            }

            $commission = PartnerCommission::create([
                'company_id' => $partner->id,
                'source_company_id' => $business->id,
                'subscription_payment_id' => $payment->id,
                'amount' => $amount,
                'rate' => $rate,
                'base_amount' => $base,
                'currency' => $payment->currency ?: 'XAF',
                'status' => 'earned',
            ]);

            $partner->owner?->notify(new PartnerCommissionEarnedNotification($commission, $business));

            return $commission;
        });
    }
}
