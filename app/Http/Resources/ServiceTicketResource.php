<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a service ticket.
 *
 * The clock columns are all here because they are the module's whole point:
 * an integration polling for breaches needs the same instants the board reads.
 * Events appear only when the controller loaded them (the show route).
 */
class ServiceTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'channel' => $this->channel,
            'category' => $this->category,
            'contact_id' => $this->contact_id,
            'visitor_name' => $this->visitor_name,
            'visitor_phone' => $this->visitor_phone,
            'assignee_id' => $this->assignee_id,
            'department_id' => $this->department_id,
            'fixed_asset_id' => $this->fixed_asset_id,
            'project_id' => $this->project_id,
            'sla_policy_id' => $this->sla_policy_id,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'response_due_at' => $this->response_due_at?->toIso8601String(),
            'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
            'first_response_at' => $this->first_response_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'paused_minutes' => (int) $this->paused_minutes,
            'resolution' => $this->resolution,
            'breached' => $this->hasBreached(),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'kind' => $event->kind,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'clock_minutes' => $event->clock_minutes,
                'note' => $event->note,
                'user_id' => $event->user_id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
