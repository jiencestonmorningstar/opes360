<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\Company;
use App\Models\Contact;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\CurrentCompany;
use App\Support\WebhookEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a business event into queued deliveries.
 *
 * ── The one rule this class exists to keep ──────────────────────────────────
 *
 * A webhook must never be the reason a payment fails.
 *
 * The services that call this — DocumentIssuer, PaymentRecorder,
 * ExpenseRecorder, DealPipeline — all do their work inside a database
 * transaction, and they call this from inside it, because that is where the
 * event is known. If this class wrote rows or threw exceptions there, then an
 * unreachable endpoint, a full disk, a bad JSON encode or a queue connection
 * that is down would roll back a payment that has already been taken at a
 * counter with a customer standing in front of it. That is a catastrophically
 * bad trade: the webhook is a courtesy to an integration, the payment is the
 * business's money.
 *
 * So the whole body of `send()` is wrapped in `DB::afterCommit`. Two things
 * follow from that, both of them wanted:
 *
 *   1. Nothing here can roll the business transaction back, because by the
 *      time any of it runs the transaction is committed and gone.
 *   2. No webhook is ever sent for something that did not happen. A payment
 *      that fails its balance check rolls back and the callback never fires,
 *      so an integration cannot be told about an invoice that does not exist.
 *
 * `DB::afterCommit` outside a transaction runs immediately, so a caller that
 * is not in one — a console command, a test — behaves the same way.
 *
 * The callback itself is then wrapped in a try/catch that logs and swallows,
 * because after-commit callbacks propagate their exceptions to whoever called
 * `DB::transaction`. Committed work with a 500 response is still the wrong
 * outcome, and it is the outcome that would look most like a bug.
 *
 * `Queue::push` rather than running the HTTP call here: an endpoint is allowed
 * to take ten seconds to answer, and the person who pressed "Record payment"
 * is not going to wait for it.
 */
class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $data  The body of the event — usually a
     *                                      resource's array form. Nothing
     *                                      sensitive that the API itself would
     *                                      not return for the same record.
     */
    public function send(string $event, array $data, ?Company $company = null): void
    {
        $company ??= app(CurrentCompany::class)->get();

        if ($company === null || ! WebhookEvents::exists($event)) {
            return;
        }

        DB::afterCommit(function () use ($event, $data, $company): void {
            try {
                $this->queue($event, $data, $company);
            } catch (Throwable $e) {
                // The business event already happened and is committed. All
                // that has been lost is a notification, and losing it quietly
                // is better than failing a request that succeeded.
                Log::warning('Webhook dispatch failed', [
                    'event' => $event,
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * `contact.created`, from the two places a person or an integration
     * deliberately adds one.
     *
     * It lives here rather than being duplicated at both call sites, and it is
     * a method rather than a model observer on purpose. An observer would also
     * fire for the customer a bulk import creates — 2,000 rows producing 2,000
     * webhooks, which is a denial of service dressed as a feature — and for
     * the placeholder contact a won deal conjures on its way to an invoice,
     * which is a side effect of invoicing rather than somebody adding a
     * customer. Neither is what a subscriber means by this event.
     */
    public function contactCreated(Contact $contact): void
    {
        $this->send(WebhookEvents::CONTACT_CREATED, [
            'id' => $contact->id,
            'type' => $contact->type,
            'name' => $contact->name,
            'company_name' => $contact->company_name,
            'email' => $contact->email,
            'phones' => $contact->phones,
            'address' => $contact->address,
            'created_at' => $contact->created_at?->toIso8601String(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function queue(string $event, array $data, Company $company): void
    {
        /*
         * Read across the tenant scope and filter by hand. The callback runs
         * after the request's transaction and may run when no current company
         * is set at all — a console command, a queue worker — and an endpoint
         * list that came back empty because of that would be a silent failure
         * rather than a loud one.
         */
        $endpoints = WebhookEndpoint::query()
            ->acrossAllCompanies()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribesTo($event));

        foreach ($endpoints as $endpoint) {
            $delivery = new WebhookDelivery;

            /*
             * The id is assigned up front so it can go inside the body as well
             * as on the row. A receiver that processes the same delivery twice
             * — because our retry crossed with its slow success — needs
             * something stable to deduplicate on, and it should not have to
             * read a header to find it.
             */
            $delivery->id = (string) $delivery->newUniqueId();

            $delivery->forceFill([
                'company_id' => $company->id,
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event,
                'payload' => [
                    'id' => $delivery->id,
                    'event' => $event,
                    'created_at' => now()->toIso8601String(),
                    'company_id' => $company->id,
                    'data' => $data,
                ],
                'status' => WebhookDelivery::PENDING,
                'next_attempt_at' => now(),
            ])->save();

            DeliverWebhook::dispatch($delivery->id);
        }
    }
}
