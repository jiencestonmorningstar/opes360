<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ShipmentResource;
use App\Models\Shipment;
use App\Services\Logistics\Dispatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Shipments over HTTP: booking, and reading the register and its history.
 *
 * Booking goes through Dispatch, so the tracking token is minted immediately
 * and the first event row is written — the customer gets their link from the
 * 201 response. Manifest work (loading, dispatching, closing) stays on the
 * screens: it is a loading-bay act with a vehicle in front of somebody.
 */
class ShipmentController extends ApiController
{
    public function __construct(private readonly Dispatch $dispatch) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('logistics.view');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(array_keys(Shipment::STATUSES))],
            'sender_id' => ['sometimes', 'string'],
            'receiver_id' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $shipments = Shipment::query()
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['sender_id']), fn ($q) => $q->where('sender_id', $filters['sender_id']))
            ->when(isset($filters['receiver_id']), fn ($q) => $q->where('receiver_id', $filters['receiver_id']))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return ShipmentResource::collection($shipments);
    }

    public function show(Shipment $shipment): ShipmentResource
    {
        $this->authorize('logistics.view');

        return ShipmentResource::make($shipment->load('events'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('logistics.manage');

        $data = $request->validate([
            'sender_id' => ['required', 'string'],
            'receiver_id' => ['required', 'string'],
            'cargo_description' => ['required', 'string', 'max:1000'],
            'from_location' => ['required', 'string', 'max:200'],
            'to_location' => ['required', 'string', 'max:200'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'freight_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $shipment = $this->dispatch->book($data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ShipmentResource::make($shipment)->response()->setStatusCode(201);
    }

    /** The event history alone — what the public tracking page shows, with ids. */
    public function events(Shipment $shipment): JsonResponse
    {
        $this->authorize('logistics.view');

        return response()->json([
            'data' => $shipment->events()->get()->map(fn ($event) => [
                'status' => $event->status,
                'note' => $event->note,
                'happened_at' => $event->happened_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
