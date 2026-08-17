<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ServiceTicketResource;
use App\Models\ServiceTicket;
use App\Services\Service\TicketDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The service desk over HTTP — ticket ingestion, the classic API use case.
 *
 * Everything goes through TicketDesk, never the model: the SLA clock and its
 * audit row have to be written in the same transaction as the status change,
 * and that invariant lives in the service. The desk's refusals come back as
 * 422 with the sentence a person could act on.
 */
class ServiceTicketController extends ApiController
{
    public function __construct(private readonly TicketDesk $desk) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('service.view');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(array_keys(ServiceTicket::STATUSES))],
            'priority' => ['sometimes', Rule::in(array_keys(ServiceTicket::PRIORITIES))],
            'assignee_id' => ['sometimes', 'integer'],
            'contact_id' => ['sometimes', 'string'],
            'open' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tickets = ServiceTicket::query()
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['priority']), fn ($q) => $q->where('priority', $filters['priority']))
            ->when(isset($filters['assignee_id']), fn ($q) => $q->where('assignee_id', $filters['assignee_id']))
            ->when(isset($filters['contact_id']), fn ($q) => $q->where('contact_id', $filters['contact_id']))
            ->when($request->boolean('open'), fn ($q) => $q->whereNotIn('status', ServiceTicket::SETTLED))
            ->latest('opened_at')
            ->paginate($filters['per_page'] ?? 25);

        return ServiceTicketResource::collection($tickets);
    }

    public function show(ServiceTicket $ticket): ServiceTicketResource
    {
        $this->authorize('service.view');

        return ServiceTicketResource::make($ticket->load('events'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('service.create');

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'contact_id' => ['nullable', 'string', 'exists:contacts,id'],
            'priority' => ['nullable', Rule::in(array_keys(ServiceTicket::PRIORITIES))],
            'channel' => ['nullable', Rule::in(array_keys(ServiceTicket::CHANNELS))],
            'category' => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'string'],
            'fixed_asset_id' => ['nullable', 'string'],
            'project_id' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer'],
            'sla_policy_id' => ['nullable', 'string'],
            'visitor_name' => ['nullable', 'string', 'max:150'],
            'visitor_phone' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $ticket = $this->desk->open($data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ServiceTicketResource::make($ticket)->response()->setStatusCode(201);
    }

    /**
     * Somebody answered the customer. Idempotent by design: only the FIRST
     * response moves the clock, so a second call changes nothing — that rule
     * is the desk's, and this endpoint merely relays it.
     */
    public function respond(Request $request, ServiceTicket $ticket): ServiceTicketResource
    {
        $this->authorize('service.update');

        return ServiceTicketResource::make($this->desk->recordResponse($ticket, $request->user()));
    }

    /**
     * `service.complete`, not `update`: saying the work is done is the trust
     * the catalogue splits out, exactly as the screens do.
     */
    public function resolve(Request $request, ServiceTicket $ticket): ServiceTicketResource|JsonResponse
    {
        $this->authorize('service.complete');

        $data = $request->validate([
            'resolution' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $ticket = $this->desk->resolve($ticket, $request->user(), $data['resolution'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ServiceTicketResource::make($ticket);
    }
}
