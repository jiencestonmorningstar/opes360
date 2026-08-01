<?php

namespace App\Services\Billing;

use App\Contracts\MobileMoneyGateway;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orange Money Web Payment API (api.orange.com/orange-money-webpay).
 *
 * Unlike MTN's direct debit, this is a hosted checkout: initiate() gets back
 * a payment_url the payer is redirected to, pays on Orange's own page, and
 * lands back on our return/cancel URLs — the actual confirmation is the
 * notif_url webhook (or checkStatus, polled the same way as MTN).
 */
class OrangeMoneyGateway implements MobileMoneyGateway
{
    public static function key(): string
    {
        return 'orange_money';
    }

    public static function label(): string
    {
        return 'Orange Money';
    }

    public function initiate(SubscriptionPayment $payment): array
    {
        $response = Http::baseUrl(config('services.orange_money.base_url'))
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post("/orange-money-webpay/{$this->country()}/v1/webpayment", [
                'merchant_key' => config('services.orange_money.merchant_key'),
                'currency' => $payment->currency,
                'order_id' => $payment->external_id,
                'amount' => (int) round((float) $payment->amount),
                'return_url' => route('billing.orange.return', ['ref' => $payment->external_id]),
                'cancel_url' => route('billing.orange.cancel', ['ref' => $payment->external_id]),
                'notif_url' => route('billing.orange.notify'),
                'lang' => config('services.orange_money.lang', 'fr'),
                'reference' => config('opes.brand.name').' '.ucfirst($payment->plan).' plan',
            ]);

        $body = (array) $response->json();

        if (! $response->successful() || empty($body['payment_url'])) {
            Log::warning('Orange Money webpayment init failed', [
                'subscription_payment_id' => $payment->id,
                'status' => $response->status(),
                'body' => $body,
            ]);

            return [
                'status' => 'failed',
                'redirect_url' => null,
                'provider_reference' => null,
                'message' => 'Orange Money did not accept the request. Please try again.',
                'raw' => $body,
            ];
        }

        return [
            'status' => 'pending',
            'redirect_url' => $body['payment_url'],
            'provider_reference' => $body['pay_token'] ?? null,
            'message' => 'Finish paying on the Orange Money page you were redirected to.',
            'raw' => $body,
        ];
    }

    public function checkStatus(SubscriptionPayment $payment): array
    {
        if (! $payment->provider_reference) {
            return ['status' => 'pending', 'provider_reference' => null, 'raw' => []];
        }

        $response = Http::baseUrl(config('services.orange_money.base_url'))
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post("/orange-money-webpay/{$this->country()}/v1/transactionstatus", [
                'order_id' => $payment->external_id,
                'amount' => (int) round((float) $payment->amount),
                'pay_token' => $payment->provider_reference,
            ]);

        $body = (array) $response->json();

        return [
            'status' => $this->mapStatus($body['status'] ?? null),
            'provider_reference' => $body['txnid'] ?? $payment->provider_reference,
            'raw' => $body,
        ];
    }

    protected function mapStatus(?string $orangeStatus): string
    {
        return match ($orangeStatus) {
            'SUCCESS' => 'successful',
            'FAILED' => 'failed',
            'EXPIRED' => 'expired',
            default => 'pending', // INITIATED, PENDING
        };
    }

    protected function country(): string
    {
        return config('services.orange_money.country', 'cm');
    }

    /** Client-credentials token, cached for a safety margin under its real TTL. */
    protected function accessToken(): string
    {
        return Cache::remember('orange_money:access_token', 570, function () {
            $response = Http::baseUrl(config('services.orange_money.base_url'))
                ->asForm()
                ->withBasicAuth(config('services.orange_money.client_id'), config('services.orange_money.client_secret'))
                ->acceptJson()
                ->post(config('services.orange_money.oauth_path', '/oauth/v3/token'), [
                    'grant_type' => 'client_credentials',
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Could not authenticate with Orange Money: '.$response->body());
            }

            $token = $response->json('access_token');

            if (! $token) {
                throw new RuntimeException('Orange Money token response had no access_token.');
            }

            return $token;
        });
    }
}
