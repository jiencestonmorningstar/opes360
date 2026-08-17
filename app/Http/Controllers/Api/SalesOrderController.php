<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\DeliveryNoteResource;
use App\Http\Resources\SalesOrderResource;
use App\Models\SalesOrder;
use App\Services\Orders\Fulfilment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Outbound fulfilment over HTTP.
 *
 * Every action goes through Fulfilment — the same service the screens call —
 * so confirming reserves through the one reservations mechanism, delivering
 * writes real stock movements, and the API cannot grow its own idea of what
 * an order promises. Refusals (credit limit, over-delivery, already
 * confirmed) come back as 422 with the service's own sentence.
 */
class SalesOrderController extends ApiController
{
    public function __construct(private readonly Fulfilment $fulfilment) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('orders.view');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([
                SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PICKING,
                SalesOrder::STATUS_DELIVERED, SalesOrder::STATUS_INVOICED, SalesOrder::STATUS_CANCELLED,
            ])],
            'contact_id' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = SalesOrder::query()
            ->with('lines')
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['contact_id']), fn ($q) => $q->where('contact_id', $filters['contact_id']))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return SalesOrderResource::collection($orders);
    }

    public function show(SalesOrder $order): SalesOrderResource
    {
        $this->authorize('orders.view');

        return SalesOrderResource::make($order->load('lines'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('orders.manage');

        $data = $request->validate([
            'contact_id' => ['required', 'string'],
            'promised_date' => ['nullable', 'date'],
            'stock_location_id' => ['nullable', 'string'],
            'source_document_id' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $order = $this->fulfilment->create($data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return SalesOrderResource::make($order)->response()->setStatusCode(201);
    }

    /**
     * Confirm: promise what exists, name what does not. `orders.confirm` is
     * its own trust because this commits the shelf. An over-limit customer is
     * refused unless `credit_override_reason` is written — and then stored on
     * the order with who wrote it, exactly as the screen does.
     */
    public function confirm(Request $request, SalesOrder $order): JsonResponse
    {
        $this->authorize('orders.confirm');

        $data = $request->validate([
            'credit_override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $shortages = $this->fulfilment->confirm($order, $request->user(), $data['credit_override_reason'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'order' => SalesOrderResource::make($order->refresh()->load('lines')),
                'shortages' => $shortages,
            ],
        ]);
    }

    /**
     * Deliver what is held — all of it, or the picks named. Returns the
     * delivery note the movements were written against.
     */
    public function deliver(Request $request, SalesOrder $order): JsonResponse
    {
        $this->authorize('orders.deliver');

        $data = $request->validate([
            'picks' => ['sometimes', 'array'],
            'picks.*' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $note = $this->fulfilment->deliver($order, $data['picks'] ?? [], $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return DeliveryNoteResource::make($note)->response()->setStatusCode(201);
    }
}
