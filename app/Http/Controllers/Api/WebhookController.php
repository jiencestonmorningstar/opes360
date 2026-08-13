<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookEndpointResource;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\WebhookEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use App\Rules\PublicHttpsUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Managing webhook endpoints over HTTP, and reading what was delivered.
 *
 * Behind `webhooks.manage`, which only an Owner or Administrator holds — see
 * Support\Permissions for why. The scope is `write` rather than a scope of its
 * own: a token that can already create invoices and customers is not made more
 * dangerous by being able to name a URL, and inventing a fifth scope for one
 * resource would be a worse contract than the four the API already teaches.
 *
 * Everything resolves through the tenant scope, so an endpoint id belonging to
 * another business does not exist here — it 404s rather than 403s, because
 * confirming that an id is real is itself a leak.
 */
class WebhookController extends ApiController
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('webhooks.view');

        return WebhookEndpointResource::collection(
            WebhookEndpoint::query()->latest()->get()
        );
    }

    public function show(WebhookEndpoint $webhook): WebhookEndpointResource
    {
        $this->authorize('webhooks.view');

        return WebhookEndpointResource::make($webhook);
    }

    /**
     * Register an endpoint.
     *
     * The response carries the signing secret, and it is the only response
     * that ever will. We keep it — both ends need the same bytes to compute
     * the same HMAC, so there is nothing to hash it against — but handing it
     * back on every read would turn a shared key into a public one.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('webhooks.manage');

        $data = $request->validate($this->rules());

        $endpoint = WebhookEndpoint::create([
            'url' => $data['url'],
            'secret' => WebhookEndpoint::newSecret(),
            'description' => $data['description'] ?? null,
            'events' => array_values(array_unique($data['events'])),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return WebhookEndpointResource::make($endpoint)->withSecret()
            ->response()->setStatusCode(201);
    }

    /**
     * Change one.
     *
     * Re-enabling an endpoint that switched itself off clears the failure
     * count as well as the flag. Leaving the count where it was would mean an
     * endpoint that has been fixed switches itself off again on its very next
     * failure, which reads as the fix not having worked.
     */
    public function update(Request $request, WebhookEndpoint $webhook): WebhookEndpointResource
    {
        $this->authorize('webhooks.manage');

        $data = $request->validate($this->rules(updating: true));

        $reactivating = ($data['is_active'] ?? false) === true && ! $webhook->isDeliverable();

        $webhook->fill(array_filter([
            'url' => $data['url'] ?? null,
            'description' => $data['description'] ?? null,
            'events' => isset($data['events']) ? array_values(array_unique($data['events'])) : null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('is_active', $data)) {
            $webhook->is_active = $data['is_active'];
        }

        $webhook->save();

        if ($reactivating) {
            $webhook->reactivate();
        }

        return WebhookEndpointResource::make($webhook->fresh());
    }

    public function destroy(WebhookEndpoint $webhook): JsonResponse
    {
        $this->authorize('webhooks.manage');

        $webhook->delete();

        return response()->json(null, 204);
    }

    /** The delivery log, newest first, optionally narrowed to one endpoint. */
    public function deliveries(Request $request): AnonymousResourceCollection
    {
        $this->authorize('webhooks.view');

        $filters = $request->validate([
            'endpoint_id' => ['sometimes', 'string'],
            'event' => ['sometimes', Rule::in(WebhookEvents::all())],
            'status' => ['sometimes', Rule::in([
                WebhookDelivery::PENDING, WebhookDelivery::DELIVERED, WebhookDelivery::FAILED,
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $deliveries = WebhookDelivery::query()
            ->when(isset($filters['endpoint_id']), fn (Builder $q) => $q->where('webhook_endpoint_id', $filters['endpoint_id']))
            ->when(isset($filters['event']), fn (Builder $q) => $q->where('event', $filters['event']))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return WebhookDeliveryResource::collection($deliveries);
    }

    /**
     * Send a failed delivery again.
     *
     * The stored payload is re-sent unchanged and the delivery keeps its id,
     * so a receiver deduplicating on `id` recognises it as the same event
     * rather than a second one. Rebuilding the body from the record as it
     * stands today would be a different message wearing the same name.
     */
    public function redeliver(WebhookDelivery $delivery): WebhookDeliveryResource|JsonResponse
    {
        $this->authorize('webhooks.manage');

        if ($delivery->status === WebhookDelivery::DELIVERED) {
            return response()->json([
                'message' => 'This delivery already succeeded.',
            ], 422);
        }

        $delivery->forceFill([
            'attempts' => 0,
            'status' => WebhookDelivery::PENDING,
            'last_error' => null,
            'response_status' => null,
            'response_body' => null,
            'next_attempt_at' => now(),
        ])->save();

        DeliverWebhook::dispatch($delivery->id);

        return WebhookDeliveryResource::make($delivery->fresh());
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $updating = false): array
    {
        return [
            /*
             * `https` only, and no unicode trickery: a webhook carries the
             * business's sales over the open internet, and a plaintext URL
             * would put them in front of every network between here and there.
             * The signature proves who sent a delivery; it does nothing to
             * hide what is in it.
             */
            'url' => [$updating ? 'sometimes' : 'required', 'url:https', 'max:500', new PublicHttpsUrl],
            'description' => ['nullable', 'string', 'max:180'],
            'events' => [$updating ? 'sometimes' : 'required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvents::all())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
