<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BillOfMaterialResource;
use App\Http\Resources\ProductionOrderResource;
use App\Models\BillOfMaterial;
use App\Models\ProductionOrder;
use App\Models\StockLocation;
use App\Services\Manufacturing\Production;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Manufacturing over HTTP: read the recipes, raise and complete orders.
 *
 * Everything moves through Production. Completing is where stock actually
 * changes hands — components out, finished goods in, at the valuation's own
 * cost — so it demands `manufacturing.complete`, the same trust as adjusting
 * stock, and a short shelf refuses the whole completion with the component
 * named.
 */
class ManufacturingController extends ApiController
{
    public function __construct(private readonly Production $production) {}

    public function boms(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manufacturing.view');

        $filters = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $boms = BillOfMaterial::query()
            ->with('lines')
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return BillOfMaterialResource::collection($boms);
    }

    public function showBom(BillOfMaterial $bom): BillOfMaterialResource
    {
        $this->authorize('manufacturing.view');

        return BillOfMaterialResource::make($bom->load('lines'));
    }

    public function orders(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manufacturing.view');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([
                ProductionOrder::STATUS_PLANNED, ProductionOrder::STATUS_IN_PROGRESS,
                ProductionOrder::STATUS_COMPLETED, ProductionOrder::STATUS_CANCELLED,
            ])],
            'bill_of_material_id' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = ProductionOrder::query()
            ->with('lines')
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['bill_of_material_id']), fn ($q) => $q->where('bill_of_material_id', $filters['bill_of_material_id']))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return ProductionOrderResource::collection($orders);
    }

    public function showOrder(ProductionOrder $order): ProductionOrderResource
    {
        $this->authorize('manufacturing.view');

        return ProductionOrderResource::make($order->load('lines'));
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $this->authorize('manufacturing.manage');

        $data = $request->validate([
            'bill_of_material_id' => ['required', 'string'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'stock_location_id' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Resolved through the tenant scope, so another company's recipe 404s.
        $bom = BillOfMaterial::query()->findOrFail($data['bill_of_material_id']);
        $location = isset($data['stock_location_id'])
            ? StockLocation::query()->findOrFail($data['stock_location_id'])
            : null;

        try {
            $order = $this->production->create(
                app(CurrentCompany::class)->get(),
                $bom,
                (float) $data['quantity'],
                $location,
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ProductionOrderResource::make($order->load('lines'))->response()->setStatusCode(201);
    }

    public function complete(Request $request, ProductionOrder $order): ProductionOrderResource|JsonResponse
    {
        $this->authorize('manufacturing.complete');

        $data = $request->validate([
            'lot_code' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $order = $this->production->complete($order, $request->user(), $data['lot_code'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ProductionOrderResource::make($order->load('lines'));
    }
}
