<?php

namespace App\Services\Partners;

use App\Models\CardIssuance;
use App\Models\Company;
use App\Models\PartnerClient;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;

/**
 * What a partner has earned, what they owe, and what is left to pay them.
 *
 * Everything here is derived from the ledger tables rather than kept as a
 * running column on the company. A stored balance and a list of transactions
 * that disagree is a support conversation nobody wins, and at this volume the
 * three sums cost nothing.
 *
 * All amounts are whole XAF. The currency has no minor unit, so integers are
 * exact and there is no rounding to argue about.
 */
class PartnerLedger
{
    /** Commission credited from referred businesses' settled payments. */
    public function earned(Company $partner): int
    {
        return (int) PartnerCommission::query()
            ->acrossAllCompanies()
            ->where('company_id', $partner->id)
            ->where('status', 'earned')
            ->sum('amount');
    }

    /** Card and letterhead fees charged for work the partner produced. */
    public function fees(Company $partner): int
    {
        return (int) CardIssuance::query()
            ->acrossAllCompanies()
            ->where('company_id', $partner->id)
            ->where('status', 'billed')
            ->sum('fee');
    }

    /**
     * Money already paid out, plus anything requested and still in flight.
     *
     * A requested payout counts against the balance immediately: otherwise a
     * partner could request the same money again while the first request sits
     * waiting to be settled.
     */
    public function withdrawn(Company $partner): int
    {
        return (int) PartnerPayout::query()
            ->acrossAllCompanies()
            ->where('company_id', $partner->id)
            ->whereIn('status', ['requested', 'paid'])
            ->sum('amount');
    }

    /**
     * What the partner could ask for right now.
     *
     * Can legitimately be negative: a partner who has printed a hundred cards
     * and enrolled nobody owes the platform rather than the other way round.
     */
    public function balance(Company $partner): int
    {
        return $this->earned($partner) - $this->fees($partner) - $this->withdrawn($partner);
    }

    public function canRequestPayout(Company $partner): bool
    {
        return $this->balance($partner) >= (int) config('opes.partners.payout_minimum');
    }

    /** The whole picture in one query pass, for the earnings page. */
    public function summary(Company $partner): array
    {
        $earned = $this->earned($partner);
        $fees = $this->fees($partner);
        $withdrawn = $this->withdrawn($partner);
        $balance = $earned - $fees - $withdrawn;

        return [
            'earned' => $earned,
            'fees' => $fees,
            'withdrawn' => $withdrawn,
            'balance' => $balance,
            'minimum' => (int) config('opes.partners.payout_minimum'),
            'can_request' => $balance >= (int) config('opes.partners.payout_minimum'),
            /*
             * Counted the same way as the sums above rather than through
             * $partner->partnerClients(), which carries the tenant scope. That
             * scope fails closed, so a caller outside the partner's own context
             * — the platform admin screen, a console command — would silently
             * read zero clients for every partner rather than erroring.
             */
            'clients' => PartnerClient::query()->acrossAllCompanies()
                ->where('company_id', $partner->id)->count(),
            'converted' => PartnerClient::query()->acrossAllCompanies()
                ->where('company_id', $partner->id)->whereNotNull('converted_company_id')->count(),
            'cards' => CardIssuance::query()->acrossAllCompanies()
                ->where('company_id', $partner->id)->where('status', 'billed')->count(),
        ];
    }
}
