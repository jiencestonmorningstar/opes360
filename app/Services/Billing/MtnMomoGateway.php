<?php

namespace App\Services\Billing;

use App\Contracts\MobileMoneyGateway;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * MTN Mobile Money Collections API (momodeveloper.mtn.com).
 *
 * RequestToPay only queues a debit prompt on the payer's phone — a 202 means
 * "asked", not "paid". The real answer arrives on MTN's callback (registered
 * against the API user out of band, in the developer portal) or by polling
 * checkStatus, which is what the billing page does while it waits.
 */
class MtnMomoGateway implements MobileMoneyGateway
{
    public static function key(): string
    {
        return 'mtn_momo';
    }

    public static function label(): string
    {
        return 'MTN Mobile Money';
    }

    public function initiate(SubscriptionPayment $payment): array
    {
        $response = $this->client()
            ->withHeaders(['X-Reference-Id' => $payment->external_id])
            ->post('/collection/v1_0/requesttopay', [
                'amount' => (string) round((float) $payment->amount),
                'currency' => $payment->currency,
                'externalId' => $payment->external_id,
                'payer' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => $this->normalizePhone($payment->phone),
                ],
                'payerMessage' => config('opes.brand.name').' '.ucfirst($payment->plan).' plan',
                'payeeNote' => 'Subscription payment '.$payment->external_id,
            ]);

        if ($response->status() !== 202) {
            Log::warning('MTN MoMo requesttopay rejected', [
                'subscription_payment_id' => $payment->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'status' => 'failed',
                'redirect_url' => null,
                'provider_reference' => null,
                'message' => 'MTN Mobile Money did not accept the request. Please try again.',
                'raw' => (array) $response->json(),
            ];
        }

        return [
            'status' => 'pending',
            'redirect_url' => null,
            'provider_reference' => $payment->external_id,
            'message' => 'A payment prompt was sent to '.$payment->phone.'. Approve it on your phone to finish.',
            'raw' => [],
        ];
    }

    public function checkStatus(SubscriptionPayment $payment): array
    {
        $response = $this->client()
            ->withHeaders(['X-Reference-Id' => $payment->external_id])
            ->get("/collection/v1_0/requesttopay/{$payment->external_id}");

        $body = (array) $response->json();

        return [
            'status' => $this->mapStatus($body['status'] ?? null),
            'provider_reference' => $body['financialTransactionId'] ?? null,
            'raw' => $body,
        ];
    }

    protected function mapStatus(?string $mtnStatus): string
    {
        return match ($mtnStatus) {
            'SUCCESSFUL' => 'successful',
            'FAILED' => 'failed',
            default => 'pending',
        };
    }

    /** Bearer client pre-loaded with the headers every Collections call needs. */
    protected function client()
    {
        return Http::baseUrl(config('services.mtn_momo.base_url'))
            ->withToken($this->accessToken())
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => config('services.mtn_momo.subscription_key'),
                'X-Target-Environment' => config('services.mtn_momo.environment'),
                'Content-Type' => 'application/json',
            ]);
    }

    /** Access tokens are short-lived; cached under the target environment so sandbox and live never collide. */
    protected function accessToken(): string
    {
        $cacheKey = 'mtn_momo:access_token:'.config('services.mtn_momo.environment');

        return Cache::remember($cacheKey, 3300, function () {
            $response = Http::baseUrl(config('services.mtn_momo.base_url'))
                ->withBasicAuth(config('services.mtn_momo.api_user'), config('services.mtn_momo.api_key'))
                ->withHeaders(['Ocp-Apim-Subscription-Key' => config('services.mtn_momo.subscription_key')])
                ->post('/collection/token/');

            if (! $response->successful()) {
                throw new RuntimeException('Could not authenticate with MTN Mobile Money: '.$response->body());
            }

            $token = $response->json('access_token');

            if (! $token) {
                throw new RuntimeException('MTN Mobile Money token response had no access_token.');
            }

            return $token;
        });
    }

    /**
     * MSISDN, digits only, with a country code and no leading zero or plus —
     * the format MTN's partyId expects. A 9-digit local number (the form
     * Cameroonian numbers are usually typed in, e.g. 670 41 62 38) gets the
     * default country code from config; anything else is assumed to already
     * carry one.
     */
    protected function normalizePhone(?string $phone): string
    {
        $digits = ltrim(preg_replace('/\D+/', '', (string) $phone) ?? '', '0');

        if (strlen($digits) === 9) {
            $digits = config('services.mtn_momo.default_country_code', '237').$digits;
        }

        return $digits;
    }
}
