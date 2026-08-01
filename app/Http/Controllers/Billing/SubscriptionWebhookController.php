<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Services\Billing\SubscriptionBiller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * MTN and Orange both call back over the open internet with no request
 * signature we can verify — anyone who guesses (or scrapes from a browser
 * history) a payment reference could POST a fake "SUCCESSFUL" body here. So
 * a webhook is never trusted for its own content: it only tells us which
 * payment to re-check, and SubscriptionBiller::refresh() gets the real
 * answer straight from the provider using our own API credentials.
 */
class SubscriptionWebhookController extends Controller
{
    public function mtnCallback(Request $request, SubscriptionBiller $biller): Response
    {
        $externalId = $request->input('externalId') ?? $request->input('referenceId');

        $this->reverify('mtn_momo', $externalId, $biller);

        return response('', 200);
    }

    public function orangeNotify(Request $request, SubscriptionBiller $biller): Response
    {
        $externalId = $request->input('order_id');

        $this->reverify('orange_money', $externalId, $biller);

        return response('', 200);
    }

    /**
     * Where Orange lands the payer's browser after they finish (or abandon)
     * paying on the hosted checkout page. Re-checking here means the payer
     * sees an up-to-date result immediately, without waiting on notif_url.
     */
    public function orangeReturn(Request $request, SubscriptionBiller $biller)
    {
        $payment = $this->find('orange_money', (string) $request->query('ref'));

        if ($payment) {
            $biller->refresh($payment);
        }

        return redirect()->route('settings.billing')->with(
            'billingStatus',
            $payment?->fresh()->isSuccessful()
                ? 'Payment received — your plan is now active.'
                : 'We could not confirm that payment yet. Check the status below or try again.'
        );
    }

    public function orangeCancel(Request $request, SubscriptionBiller $biller)
    {
        $payment = $this->find('orange_money', (string) $request->query('ref'));

        // Re-check rather than trust the cancel itself — the payer may have
        // actually completed payment on Orange's page moments before backing
        // out to this URL.
        if ($payment) {
            $biller->refresh($payment);
        }

        return redirect()->route('settings.billing')->with('billingStatus', 'Payment cancelled.');
    }

    protected function reverify(string $provider, ?string $externalId, SubscriptionBiller $biller): void
    {
        if (! $externalId) {
            return;
        }

        $payment = $this->find($provider, $externalId);

        if (! $payment) {
            Log::info('Subscription webhook for unknown payment', ['provider' => $provider, 'external_id' => $externalId]);

            return;
        }

        $biller->refresh($payment);
    }

    protected function find(string $provider, string $externalId): ?SubscriptionPayment
    {
        return SubscriptionPayment::query()
            ->acrossAllCompanies()
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->first();
    }
}
