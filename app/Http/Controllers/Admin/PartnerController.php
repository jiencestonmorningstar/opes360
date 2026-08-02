<?php

namespace App\Http\Controllers\Admin;

use App\Models\Company;
use App\Models\PartnerPayout;
use App\Models\PlatformAdminActivity;
use App\Notifications\PartnerPayoutSettledNotification;
use App\Services\Partners\PartnerLedger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The secretariat programme, seen from the platform's side.
 *
 * Two things happen here that cannot happen anywhere else: seeing what every
 * partner is owed in one list, and settling a payout. The money itself moves
 * outside the app — mobile money, in practice — so "settling" means recording
 * that it was sent, which is what turns a request into a closed one and stops
 * the partner's balance being held against it forever.
 */
class PartnerController extends Controller
{
    public function index(Request $request, PartnerLedger $ledger)
    {
        $partners = Company::query()
            ->where('kind', 'secretariat')
            ->withCount(['partnerClients', 'referrals'])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $partner) => [
                'company' => $partner,
                'summary' => $ledger->summary($partner),
            ]);

        $payouts = PartnerPayout::query()
            ->acrossAllCompanies()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        // Names for the payout rows: the payout table carries only company_id,
        // and a partner could have been soft-deleted since requesting.
        $companies = Company::withTrashed()
            ->whereIn('id', $payouts->getCollection()->pluck('company_id')->unique())
            ->get()
            ->keyBy('id');

        return view('admin.partners.index', [
            'partners' => $partners,
            'payouts' => $payouts,
            'companies' => $companies,
            'selectedStatus' => $request->query('status'),
            'owed' => $partners->sum(fn (array $row) => max(0, $row['summary']['balance'])),
            'openRequests' => PartnerPayout::query()->acrossAllCompanies()->where('status', 'requested')->count(),
        ]);
    }

    /**
     * Record that a payout was sent, or refuse it.
     *
     * Deliberately not reversible from here: a payout marked paid has money
     * behind it, and an undo button invites a second transfer against the same
     * balance. A mistake is corrected by a new request.
     */
    public function settle(Request $request, string $payout)
    {
        /*
         * Read across every tenant on purpose. PartnerPayout is tenant-scoped
         * like everything else a company owns, and the admin guard has no
         * current company — route-model binding would resolve through a scope
         * that fails closed and 404 on a payout that plainly exists.
         */
        $payout = PartnerPayout::query()->acrossAllCompanies()->find($payout);

        abort_if($payout === null, 404);
        abort_unless($payout->isOpen(), 409, 'That payout has already been settled.');

        $validated = $request->validate([
            'decision' => ['required', 'in:paid,rejected'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $payout->forceFill([
            'status' => $validated['decision'],
            'note' => $validated['note'] ?? null,
            'settled_at' => now(),
        ])->save();

        $partner = Company::withTrashed()->find($payout->company_id);

        PlatformAdminActivity::log(
            $request->user('admin'),
            'partner.payout.'.$validated['decision'],
            $partner,
            ['amount' => $payout->amount, 'method' => $payout->method],
        );

        $partner?->owner?->notify(new PartnerPayoutSettledNotification($payout->fresh()));

        return back()->with('status', $validated['decision'] === 'paid'
            ? 'Payout marked as sent.'
            : 'Payout rejected — the balance is available to the partner again.');
    }
}
