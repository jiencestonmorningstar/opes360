<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a webhook endpoint.
 *
 * The secret is absent from every response but the one that created it. A
 * signing key readable from a list endpoint is not a signing key: a token with
 * only `read` could harvest one per endpoint and forge deliveries for the rest
 * of the business's life. So including it is a deliberate act at one call
 * site — `withSecret()` — rather than something a field name controls.
 */
class WebhookEndpointResource extends JsonResource
{
    protected bool $revealSecret = false;

    /** Only ever called from the endpoint that just minted it. */
    public function withSecret(): static
    {
        $this->revealSecret = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'url' => $this->url,
            'description' => $this->description,
            'events' => $this->events ?? [],
            'is_active' => (bool) $this->is_active,
            'consecutive_failures' => (int) $this->consecutive_failures,
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'disabled_reason' => $this->disabled_reason,
            'secret' => $this->revealSecret ? $this->resource->secret : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ], fn (string $key) => $key !== 'secret' || $this->revealSecret, ARRAY_FILTER_USE_KEY);
    }
}
