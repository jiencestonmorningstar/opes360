<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * What the business sells.
 *
 * Stock levels are deliberately not writable here. Quantity on hand is the
 * result of movements the stock ledger records — receipts, sales, transfers,
 * stocktakes — and an endpoint that let a caller set it directly would put the
 * books and the shelf permanently out of agreement with no trail explaining
 * why.
 */
class ItemController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Item::class);

        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(['product', 'service'])],
            'q' => ['sometimes', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $items = Item::query()
            ->when(isset($filters['type']), fn (Builder $q) => $q->where('type', $filters['type']))
            ->when($request->has('active'), fn (Builder $q) => $q->where('is_active', $request->boolean('active')))
            ->when(isset($filters['q']), function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);

        return ItemResource::collection($items);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Item::class);

        $data = $request->validate($this->rules());

        $item = Item::create($data + [
            'type' => $data['type'] ?? 'product',
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return ItemResource::make($item)->response()->setStatusCode(201);
    }

    public function show(Item $item): ItemResource
    {
        $this->authorize('view', $item);

        return ItemResource::make($item);
    }

    public function update(Request $request, Item $item): ItemResource
    {
        $this->authorize('update', $item);

        $item->update($request->validate($this->rules(updating: true)));

        return ItemResource::make($item->fresh());
    }

    public function destroy(Item $item): JsonResponse
    {
        $this->authorize('delete', $item);

        $item->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:200'],
            'type' => ['sometimes', Rule::in(['product', 'service'])],
            'sku' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['nullable', 'string', 'max:30'],
            'price' => [$required, 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
