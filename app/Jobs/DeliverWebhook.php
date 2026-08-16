<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpsUrl;
use App\Support\WebhookSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts one delivery to one endpoint, and decides what happens next.
 *
 * ── Why it carries an id rather than the model ──────────────────────────────
 *
 * A delivery may sit on the queue for six hours between attempts. Serialising
 * the model would freeze a copy of the row as it was when the job was made,
 * and the endpoint may have been switched off in the meantime — by its owner,
 * or by this job's own sibling. Re-reading it means an endpoint that was
 * disabled while a retry was waiting does not get posted to anyway.
 *
 * ── Why the retries are hand-rolled ─────────────────────────────────────────
 *
 * Laravel's own `$tries` + `backoff()` would work, and would be less code. It
 * would also mean this job signals failure by *throwing*, and on the `sync`
 * connection — which is what a small install and the test suite both run — a
 * thrown exception travels straight back up into the request that recorded the
 * payment. The whole point of this feature is that a webhook cannot do that.
 *
 * So nothing here ever throws. A failed attempt is written down, the next
 * attempt is queued with its own delay, and when the schedule runs out the
 * delivery is marked failed and the endpoint told about it.
 *
 * ── Timeouts ────────────────────────────────────────────────────────────────
 *
 * Five seconds to connect, ten in total. An endpoint that hangs — a server
 * that accepts the connection and never answers — would otherwise hold a
 * worker open indefinitely, and one such endpoint is enough to stop every
 * other business's webhooks on a single-worker install. A receiver that needs
 * longer than ten seconds should answer 200 and do its work afterwards, which
 * is what the documentation tells it to do.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The queue does not retry this job. The retry schedule is this class's
     * own business — see the docblock — and letting both mechanisms run would
     * multiply the attempts by each other.
     */
    public int $tries = 1;

    public function __construct(public string $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()
            ->acrossAllCompanies()
            ->find($this->deliveryId);

        if ($delivery === null || $delivery->status === WebhookDelivery::DELIVERED) {
            return;
        }

        $endpoint = WebhookEndpoint::query()
            ->acrossAllCompanies()
            ->find($delivery->webhook_endpoint_id);

        if ($endpoint === null || ! $endpoint->isDeliverable()) {
            $delivery->forceFill([
                'status' => WebhookDelivery::FAILED,
                'last_error' => 'The endpoint was switched off before this delivery went out.',
                'next_attempt_at' => null,
            ])->save();

            return;
        }

        /*
         * Re-checked here, not only when the endpoint was saved. A name that
         * resolved to a public address at registration can resolve to
         * 169.254.169.254 by the time this runs — that is DNS rebinding, and
         * it turns a validated endpoint into a request for the cloud
         * provider's instance metadata. Cheap to check, and the check has to
         * happen next to the request it guards.
         */
        if (! PublicHttpsUrl::isAllowed($endpoint->url, $reason)) {
            $delivery->forceFill([
                'status' => WebhookDelivery::FAILED,
                'last_error' => $reason ?? 'That address is not reachable from the public internet.',
                'next_attempt_at' => null,
            ])->save();

            $endpoint->recordFailure($reason ?? 'The address stopped being publicly reachable.');

            return;
        }

        // The body is signed and sent as the exact bytes encoded here. It is
        // never re-encoded downstream: a receiver recomputing the HMAC hashes
        // what arrived, and a key order that changed between signing and
        // sending would break every signature for no visible reason.
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'Opes360-Webhooks/1',
                'Opes-Event' => $delivery->event,
                'Opes-Delivery' => $delivery->id,
                'Opes-Signature' => WebhookSignature::header($timestamp, $body, $endpoint->secret),
            ])
                ->connectTimeout(5)
                ->timeout(10)
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            if ($response->successful()) {
                $delivery->forceFill([
                    'status' => WebhookDelivery::DELIVERED,
                    'response_status' => $response->status(),
                    'response_body' => WebhookDelivery::truncate($response->body()),
                    'last_error' => null,
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                ])->save();

                $endpoint->recordSuccess();

                return;
            }

            $this->giveUpOrRetry(
                $delivery,
                $endpoint,
                sprintf('The endpoint answered %d.', $response->status()),
                $response->status(),
                $response->body(),
            );
        } catch (Throwable $e) {
            // Connection refused, DNS failure, TLS error, timeout. No response
            // status to record, and nothing that should escape this method.
            $this->giveUpOrRetry($delivery, $endpoint, $e->getMessage(), null, null);
        }
    }

    /**
     * Book the failure, then either queue the next attempt or stop.
     *
     * The endpoint's consecutive-failure count moves only when a delivery is
     * abandoned altogether, not on every attempt. Otherwise a single bad
     * delivery would burn five of the fifteen failures an endpoint is allowed,
     * and three unlucky events during one deploy would switch a healthy
     * integration off.
     */
    protected function giveUpOrRetry(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        string $error,
        ?int $status,
        ?string $body,
    ): void {
        $delay = $delivery->hasAttemptsLeft()
            ? WebhookDelivery::backoffAfter($delivery->attempts)
            : null;

        $delivery->forceFill([
            'status' => $delay === null ? WebhookDelivery::FAILED : WebhookDelivery::PENDING,
            'response_status' => $status,
            'response_body' => WebhookDelivery::truncate($body),
            'last_error' => Str::limit($error, 490),
            'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay),
        ])->save();

        if ($delay === null) {
            $endpoint->recordFailure($error);

            return;
        }

        self::dispatch($delivery->id)->delay(now()->addSeconds($delay));
    }
}
