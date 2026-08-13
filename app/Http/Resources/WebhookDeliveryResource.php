<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of one delivery attempt.
 *
 * The payload we sent is included. That is the point of the endpoint: the
 * question it answers is "you say you sent it, what exactly did you send", and
 * an answer that omitted the body would not answer it.
 */
class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'endpoint_id' => $this->webhook_endpoint_id,
            'event' => $this->event,
            'status' => $this->status,
            'attempts' => (int) $this->attempts,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'last_error' => $this->last_error,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'payload' => $this->payload,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
