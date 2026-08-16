<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Jobs\DeliverWebhook;
use App\Livewire\Settings\Webhooks as WebhooksScreen;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\PaymentRecorder;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use App\Support\WebhookEvents;
use App\Support\WebhookSignature;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Telling an integration what happened, without ever letting that cost the
 * business the thing that happened.
 *
 * The tests below are grouped around the two promises this feature makes. The
 * first is to the receiver: what arrives is provably ours, provably recent,
 * and retried on a schedule that is written down. The second is to the
 * business: none of that can ever be the reason a payment fails, an endpoint
 * of theirs is visible to nobody else, and only an owner decides where a copy
 * of the takings gets sent.
 */
class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = $this->makeCompany('acme');

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
        ChartOfAccounts::seed($this->company);

        Sanctum::actingAs($this->owner, ['*']);
    }

    protected function makeCompany(string $prefix): Company
    {
        return Company::create([
            'slug' => $prefix.'-'.Str::lower(Str::random(6)),
            'name' => Str::headline($prefix).' Sarl',
            'owner_id' => User::factory()->create()->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
    }

    /** @param  array<int, string>  $events */
    protected function endpoint(array $events = [WebhookEvents::PAYMENT_RECORDED], ?Company $company = null): WebhookEndpoint
    {
        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill([
            'company_id' => ($company ?? $this->company)->id,
            'url' => 'https://hooks.example.com/opes',
            'secret' => WebhookEndpoint::newSecret(),
            'events' => $events,
            'is_active' => true,
        ])->save();

        return $endpoint;
    }

    protected function issuedInvoice(float $amount = 50000): Document
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Boulangerie Nkolbisson']);

        $id = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'issue' => true,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount]],
        ])->json('data.id');

        return Document::findOrFail($id);
    }

    // ── The event actually fires ─────────────────────────────────────────────

    /**
     * The whole feature in one assertion: a real business event, done the way
     * the business does it, produces a queued delivery carrying the payload.
     */
    public function test_recording_a_payment_queues_a_delivery_to_a_subscribed_endpoint(): void
    {
        Queue::fake();

        $endpoint = $this->endpoint([WebhookEvents::PAYMENT_RECORDED]);
        $invoice = $this->issuedInvoice(20000);

        app(PaymentRecorder::class)->record($invoice, $this->owner, 20000, PaymentMethod::Cash);

        Queue::assertPushed(DeliverWebhook::class);

        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->assertSame(WebhookEvents::PAYMENT_RECORDED, $delivery->event);
        $this->assertSame($endpoint->id, $delivery->webhook_endpoint_id);
        $this->assertSame(WebhookDelivery::PENDING, $delivery->status);
        $this->assertEquals(20000, $delivery->payload['data']['amount']);
        $this->assertSame($invoice->id, $delivery->payload['data']['document_id']);

        // The delivery id is inside the body as well as on the row: it is what
        // a receiver deduplicates a crossed retry on.
        $this->assertSame($delivery->id, $delivery->payload['id']);
    }

    /** An endpoint that did not ask for this event is not told about it. */
    public function test_an_endpoint_only_hears_about_the_events_it_subscribed_to(): void
    {
        Queue::fake();

        $this->endpoint([WebhookEvents::DEAL_WON]);

        $invoice = $this->issuedInvoice(5000);
        app(PaymentRecorder::class)->record($invoice, $this->owner, 5000, PaymentMethod::Cash);

        // Not `assertNothingPushed`: recording a payment legitimately queues
        // a notification of its own, and this is a statement about webhooks.
        Queue::assertNotPushed(DeliverWebhook::class);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    /** Issuing a document is the other event most integrations want. */
    public function test_issuing_a_document_queues_a_delivery(): void
    {
        Queue::fake();

        $this->endpoint([WebhookEvents::DOCUMENT_ISSUED]);
        $invoice = $this->issuedInvoice(7500);

        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->assertSame(WebhookEvents::DOCUMENT_ISSUED, $delivery->event);
        $this->assertSame($invoice->id, $delivery->payload['data']['id']);
        $this->assertSame($invoice->number, $delivery->payload['data']['number']);
    }

    // ── The signature ────────────────────────────────────────────────────────

    /**
     * What is actually posted verifies with the shared secret — and does not
     * verify with anybody else's.
     */
    public function test_the_delivered_signature_verifies_with_the_secret_and_fails_with_another(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);

        $delivery = $this->pendingDelivery($endpoint, ['title' => 'Fourniture bureau']);
        (new DeliverWebhook($delivery->id))->handle();

        $sent = null;
        Http::assertSent(function (ClientRequest $request) use (&$sent) {
            $sent = $request;

            return true;
        });

        $header = $sent->header('Opes-Signature')[0];
        $body = $sent->body();

        $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $header);
        $this->assertTrue(WebhookSignature::verify($header, $body, $endpoint->secret));

        // The point of the exercise. Anybody can post to a URL; only the
        // holder of the secret can produce this header for this body.
        $this->assertFalse(WebhookSignature::verify($header, $body, 'whsec_not_the_secret'));

        // And the body is covered, not just the timestamp.
        $this->assertFalse(WebhookSignature::verify($header, $body.'x', $endpoint->secret));
    }

    /**
     * A captured delivery replayed later is detectable, which is the whole
     * reason the timestamp is inside the signed material rather than beside it.
     */
    public function test_a_replayed_delivery_is_detectable_by_its_age(): void
    {
        $secret = 'whsec_test';
        $body = '{"id":"01j","event":"deal.won"}';

        $capturedAt = 1_755_000_000;
        $header = WebhookSignature::header($capturedAt, $body, $secret);

        // Replayed within the tolerance window: genuine, and accepted.
        $this->assertTrue(WebhookSignature::verify($header, $body, $secret, now: $capturedAt + 60));

        // Replayed an hour later: still a perfectly valid HMAC over a
        // perfectly genuine body, and refused anyway, because the timestamp it
        // covers says the delivery is not from now.
        $this->assertFalse(WebhookSignature::verify($header, $body, $secret, now: $capturedAt + 3600));

        // And the age cannot be moved without the secret: swapping in a fresh
        // timestamp leaves a digest that no longer matches what it signs.
        $forged = sprintf('t=%d,v1=%s', $capturedAt + 3600, Str::after($header, 'v1='));
        $this->assertFalse(WebhookSignature::verify($forged, $body, $secret, now: $capturedAt + 3600));
    }

    /** The documented worked example has to be the one the code produces. */
    public function test_the_documented_verification_example_is_the_real_scheme(): void
    {
        $timestamp = 1_755_000_000;
        $body = '{"id":"01j","event":"deal.won"}';

        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test'),
            WebhookSignature::compute($timestamp, $body, 'whsec_test'),
        );
    }

    // ── Retries, and giving up ───────────────────────────────────────────────

    /** A failed attempt books the next one at the documented interval. */
    public function test_a_failure_schedules_the_next_attempt_on_the_backoff_schedule(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        Queue::fake();

        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $delivery = $this->pendingDelivery($endpoint);

        (new DeliverWebhook($delivery->id))->handle();

        $delivery->refresh();

        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(WebhookDelivery::PENDING, $delivery->status);
        $this->assertSame(500, $delivery->response_status);

        // First retry, a minute out — and still queued, not abandoned.
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($delivery->next_attempt_at), 5);
        Queue::assertPushed(DeliverWebhook::class);

        // The published schedule, asserted against the constant the job reads,
        // so the documentation and the behaviour cannot drift apart.
        $this->assertSame([60, 300, 1800, 7200, 21600], WebhookDelivery::BACKOFF);
    }

    /** After the last attempt it stops, and says why. */
    public function test_a_delivery_gives_up_after_five_attempts(): void
    {
        // sync queue: the job re-dispatches itself and runs the whole schedule
        // in one call, which is exactly what makes the count assertable.
        Http::fake(['*' => Http::response('nope', 500)]);

        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $delivery = $this->pendingDelivery($endpoint);

        (new DeliverWebhook($delivery->id))->handle();

        $delivery->refresh();

        $this->assertSame(WebhookDelivery::MAX_ATTEMPTS, $delivery->attempts);
        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertStringContainsString('500', (string) $delivery->last_error);

        Http::assertSentCount(WebhookDelivery::MAX_ATTEMPTS);

        // One bad delivery is one failure against the endpoint, not five.
        $this->assertSame(1, $endpoint->fresh()->consecutive_failures);
    }

    /** A connection that never answers is a failure, not a hung worker. */
    public function test_a_connection_error_is_recorded_rather_than_thrown(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $delivery = $this->pendingDelivery($endpoint);

        (new DeliverWebhook($delivery->id))->handle();

        $delivery->refresh();

        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertNull($delivery->response_status);
        $this->assertStringContainsString('Connection refused', (string) $delivery->last_error);
    }

    /**
     * Enough abandoned deliveries in a row and we stop trying — and the
     * business is told what the last error was, on the screen where they will
     * ask the question.
     */
    public function test_an_endpoint_switches_itself_off_after_repeated_failure(): void
    {
        Http::fake(['*' => Http::response('gone', 500)]);

        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);

        for ($i = 0; $i < WebhookEndpoint::FAILURE_LIMIT; $i++) {
            (new DeliverWebhook($this->pendingDelivery($endpoint)->id))->handle();
        }

        $endpoint->refresh();

        $this->assertFalse($endpoint->is_active);
        $this->assertNotNull($endpoint->disabled_at);
        $this->assertStringContainsString('500', (string) $endpoint->disabled_reason);
        $this->assertFalse($endpoint->isDeliverable());
    }

    /** One success forgives the failures before it. */
    public function test_a_success_clears_the_failure_count(): void
    {
        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $endpoint->forceFill(['consecutive_failures' => 9])->save();

        Http::fake(['*' => Http::response('', 204)]);

        $delivery = $this->pendingDelivery($endpoint);
        (new DeliverWebhook($delivery->id))->handle();

        $this->assertSame(WebhookDelivery::DELIVERED, $delivery->fresh()->status);
        $this->assertSame(0, $endpoint->fresh()->consecutive_failures);
    }

    /** A response big enough to fill a disk does not get to. */
    public function test_a_huge_response_body_is_truncated_before_it_is_stored(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 50_000), 500)]);

        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $delivery = $this->pendingDelivery($endpoint);

        (new DeliverWebhook($delivery->id))->handle();

        $this->assertLessThan(
            WebhookDelivery::TRUNCATE_BODY_AT + 100,
            strlen((string) $delivery->fresh()->response_body),
        );
    }

    // ── The promise to the business ──────────────────────────────────────────

    /**
     * The rule the whole design exists to keep.
     *
     * The endpoint is unreachable and every attempt fails. The payment is
     * still recorded, the receipt still issued, the invoice still settled. A
     * webhook is a courtesy to an integration; the payment is the business's
     * money, and one must never be able to undo the other.
     */
    public function test_a_failing_webhook_does_not_roll_back_the_payment_that_triggered_it(): void
    {
        Http::fake(fn () => throw new ConnectionException('No route to host'));

        $this->endpoint([WebhookEvents::PAYMENT_RECORDED]);
        $invoice = $this->issuedInvoice(30000);

        $payment = app(PaymentRecorder::class)->record($invoice, $this->owner, 30000, PaymentMethod::Cash);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 30000]);
        $this->assertSame(0.0, (float) $invoice->fresh()->balance);
        $this->assertNotNull($payment->receipt ?? Payment::findOrFail($payment->id));

        // And the failure is recorded rather than lost.
        $this->assertSame(WebhookDelivery::FAILED, WebhookDelivery::query()->firstOrFail()->status);
    }

    /**
     * The other half of the same promise: nothing is announced for something
     * that did not happen. An overpayment is refused inside the transaction,
     * so the dispatch never runs.
     */
    public function test_nothing_is_sent_for_a_business_event_that_rolled_back(): void
    {
        Queue::fake();

        $this->endpoint([WebhookEvents::PAYMENT_RECORDED]);
        $invoice = $this->issuedInvoice(1000);

        try {
            app(PaymentRecorder::class)->record($invoice, $this->owner, 999999, PaymentMethod::Cash);
            $this->fail('An overpayment should have been refused.');
        } catch (RuntimeException) {
            // Expected.
        }

        Queue::assertNotPushed(DeliverWebhook::class);
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    /** An endpoint that has been switched off is not posted to. */
    public function test_a_disabled_endpoint_receives_nothing(): void
    {
        Http::fake();

        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $delivery = $this->pendingDelivery($endpoint);

        $endpoint->forceFill(['is_active' => false, 'disabled_at' => now()])->save();

        (new DeliverWebhook($delivery->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(WebhookDelivery::FAILED, $delivery->fresh()->status);
    }

    // ── Tenancy and permission ───────────────────────────────────────────────

    /** Another business's endpoint does not exist here. */
    public function test_another_companys_endpoints_are_not_visible_and_404(): void
    {
        $other = $this->makeCompany('rival');
        $theirs = $this->endpoint([WebhookEvents::DEAL_WON], $other);

        $mine = $this->endpoint([WebhookEvents::DEAL_WON]);

        $list = $this->getJson('/api/v1/webhooks')->assertOk()->json('data');

        $this->assertCount(1, $list);
        $this->assertSame($mine->id, $list[0]['id']);

        $this->getJson('/api/v1/webhooks/'.$theirs->id)->assertNotFound();
        $this->patchJson('/api/v1/webhooks/'.$theirs->id, ['url' => 'https://evil.example.com/x'])->assertNotFound();
        $this->deleteJson('/api/v1/webhooks/'.$theirs->id)->assertNotFound();
    }

    /**
     * Only an Owner or Administrator manages these. A manager can see today's
     * takings; that is not the same as being able to arrange a permanent copy
     * of them.
     */
    public function test_a_non_owner_cannot_manage_endpoints(): void
    {
        $manager = User::factory()->create();
        $this->joinCompany($this->company, $manager, Role::MANAGER);
        $manager->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($manager, ['*']);

        $this->postJson('/api/v1/webhooks', [
            'url' => 'https://manager.example.com/hook',
            'events' => [WebhookEvents::PAYMENT_RECORDED],
        ])->assertForbidden();

        $this->getJson('/api/v1/webhooks')->assertForbidden();

        // And not by the back door either.
        $this->actingAs($manager)->get('/settings/webhooks')->assertForbidden();
    }

    // ── The API surface ──────────────────────────────────────────────────────

    /** Creating one, and the once-only secret. */
    public function test_creating_an_endpoint_returns_the_secret_exactly_once(): void
    {
        $response = $this->postJson('/api/v1/webhooks', [
            'url' => 'https://hooks.example.com/opes',
            'description' => 'Warehouse',
            'events' => [WebhookEvents::DOCUMENT_ISSUED, WebhookEvents::PAYMENT_RECORDED],
        ])->assertCreated();

        $secret = $response->json('data.secret');

        $this->assertIsString($secret);
        $this->assertStringStartsWith('whsec_', $secret);

        $id = $response->json('data.id');

        // Never again, on any read.
        $this->getJson('/api/v1/webhooks/'.$id)->assertOk()->assertJsonMissingPath('data.secret');
        $this->getJson('/api/v1/webhooks')->assertOk()->assertJsonMissingPath('data.0.secret');
    }

    /** Plain http is refused: a delivery carries the business's figures. */
    public function test_an_endpoint_url_must_be_https(): void
    {
        $this->postJson('/api/v1/webhooks', [
            'url' => 'http://hooks.example.com/opes',
            'events' => [WebhookEvents::DEAL_WON],
        ])->assertStatus(422)->assertJsonValidationErrors('url');
    }

    /** An event nobody publishes cannot be subscribed to. */
    public function test_an_unknown_event_is_refused(): void
    {
        $this->postJson('/api/v1/webhooks', [
            'url' => 'https://hooks.example.com/opes',
            'events' => ['payment.deleted'],
        ])->assertStatus(422)->assertJsonValidationErrors('events.0');
    }

    /** Turning one back on forgives the failures that stopped it. */
    public function test_reactivating_an_endpoint_clears_the_failure_count(): void
    {
        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $endpoint->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_reason' => 'Switched off after 15 failed deliveries in a row.',
            'consecutive_failures' => 15,
        ])->save();

        $this->patchJson('/api/v1/webhooks/'.$endpoint->id, ['is_active' => true])->assertOk();

        $endpoint->refresh();

        $this->assertTrue($endpoint->is_active);
        $this->assertNull($endpoint->disabled_at);
        $this->assertSame(0, $endpoint->consecutive_failures);
    }

    /** Redelivery sends the body the first attempt sent, under the same id. */
    public function test_a_failed_delivery_can_be_sent_again_over_the_api(): void
    {
        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $delivery = $this->pendingDelivery($endpoint, ['title' => 'Marché de mars']);

        $server = $this->flakyEndpointThatRecovers();

        (new DeliverWebhook($delivery->id))->handle();

        $this->assertSame(WebhookDelivery::FAILED, $delivery->fresh()->status);

        $server->working = true;

        $this->postJson('/api/v1/webhooks/deliveries/'.$delivery->id.'/redeliver')->assertOk();

        $delivery->refresh();

        $this->assertSame(WebhookDelivery::DELIVERED, $delivery->status);
        $this->assertSame('Marché de mars', $delivery->payload['data']['title']);

        // Same id, so a receiver that already saw it recognises it as the same
        // event rather than a second one.
        $this->assertSame($delivery->id, $delivery->payload['id']);
    }

    /** Something that already arrived is not sent a second time on request. */
    public function test_redelivering_a_successful_delivery_is_refused(): void
    {
        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $delivery = $this->pendingDelivery($endpoint);
        $delivery->forceFill(['status' => WebhookDelivery::DELIVERED, 'delivered_at' => now()])->save();

        $this->postJson('/api/v1/webhooks/deliveries/'.$delivery->id.'/redeliver')->assertStatus(422);
    }

    /** The delivery log is readable, and scoped. */
    public function test_the_delivery_log_lists_only_this_companys_deliveries(): void
    {
        $mine = $this->pendingDelivery($this->endpoint([WebhookEvents::DEAL_WON]));

        $other = $this->makeCompany('rival');
        $this->pendingDelivery($this->endpoint([WebhookEvents::DEAL_WON], $other), [], $other);

        $data = $this->getJson('/api/v1/webhooks/deliveries')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($mine->id, $data[0]['id']);
    }

    // ── The screen ───────────────────────────────────────────────────────────

    /** The settings screen creates one and shows the secret once. */
    public function test_the_settings_screen_creates_an_endpoint_and_reveals_the_secret_once(): void
    {
        $component = Livewire::actingAs($this->owner)->test(WebhooksScreen::class)
            ->set('url', 'https://hooks.example.com/from-the-screen')
            ->set('description', 'Warehouse')
            ->set('events', [WebhookEvents::DOCUMENT_ISSUED])
            ->call('save');

        $endpoint = WebhookEndpoint::query()->firstOrFail();

        $this->assertSame('https://hooks.example.com/from-the-screen', $endpoint->url);
        $component->assertSet('plainTextSecret', $endpoint->secret);

        // Dismissed, and gone from the component's state.
        $component->call('dismissSecret')->assertSet('plainTextSecret', null);
    }

    /** And pauses one, without deleting the history behind it. */
    public function test_the_settings_screen_pauses_and_resumes_an_endpoint(): void
    {
        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);

        $component = Livewire::actingAs($this->owner)->test(WebhooksScreen::class);

        $component->call('toggle', $endpoint->id);
        $this->assertFalse($endpoint->fresh()->is_active);

        $component->call('toggle', $endpoint->id);
        $this->assertTrue($endpoint->fresh()->is_active);
    }

    /** Redelivery from the screen, which is where a business will do it. */
    public function test_the_settings_screen_can_send_a_failed_delivery_again(): void
    {
        $endpoint = $this->endpoint([WebhookEvents::DEAL_WON]);
        $delivery = $this->pendingDelivery($endpoint);

        $server = $this->flakyEndpointThatRecovers();

        (new DeliverWebhook($delivery->id))->handle();
        $this->assertSame(WebhookDelivery::FAILED, $delivery->fresh()->status);

        $server->working = true;

        Livewire::actingAs($this->owner)->test(WebhooksScreen::class)
            ->call('redeliver', $delivery->id);

        $this->assertSame(WebhookDelivery::DELIVERED, $delivery->fresh()->status);
    }

    /** The screen has to be linked, or nobody finds it. */
    public function test_the_settings_index_links_to_the_webhooks_screen(): void
    {
        $this->actingAs($this->owner)->get('/settings')
            ->assertOk()
            ->assertSee(route('settings.webhooks'), escape: false);
    }

    /**
     * A receiving server that is down, and can be brought back up mid-test.
     *
     * `Http::fake()` accumulates its stubs rather than replacing them, so
     * faking a 500 and then faking a 200 leaves the 500 winning. A mutable
     * switch is the honest way to say "and then their server came back".
     */
    protected function flakyEndpointThatRecovers(): object
    {
        $server = new class
        {
            public bool $working = false;
        };

        Http::fake(fn () => $server->working
            ? Http::response('ok', 200)
            : Http::response('nope', 500));

        return $server;
    }

    /**
     * A pending delivery, made the way the dispatcher makes one, without
     * needing a business event to produce it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function pendingDelivery(WebhookEndpoint $endpoint, array $data = [], ?Company $company = null): WebhookDelivery
    {
        $company ??= $this->company;

        $delivery = new WebhookDelivery;
        $delivery->id = (string) $delivery->newUniqueId();

        $delivery->forceFill([
            'company_id' => $company->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => WebhookEvents::DEAL_WON,
            'payload' => [
                'id' => $delivery->id,
                'event' => WebhookEvents::DEAL_WON,
                'created_at' => now()->toIso8601String(),
                'company_id' => $company->id,
                'data' => $data,
            ],
            'status' => WebhookDelivery::PENDING,
            'next_attempt_at' => now(),
        ])->save();

        return $delivery;
    }
}
