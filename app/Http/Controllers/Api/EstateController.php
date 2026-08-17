<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PropertyResource;
use App\Http\Resources\TenancyResource;
use App\Models\Property;
use App\Models\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The property register over HTTP, read only.
 *
 * Deliberately so: moving a tenant in raises a lease, posts the deposit to
 * the ledger and starts the rent schedule in one transaction, and moving them
 * out settles money — acts the Tenancies service performs with a person on a
 * screen answering for them. What an integration wants from this vertical is
 * the register: which doors, who is in them, at what rent.
 */
class EstateController extends ApiController
{
    public function properties(Request $request): AnonymousResourceCollection
    {
        $this->authorize('estate.view');

        $filters = $request->validate([
            'kind' => ['sometimes', Rule::in(array_keys(Property::KINDS))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $properties = Property::query()
            ->with('units')
            ->when(isset($filters['kind']), fn ($q) => $q->where('kind', $filters['kind']))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);

        return PropertyResource::collection($properties);
    }

    public function showProperty(Property $property): PropertyResource
    {
        $this->authorize('estate.view');

        return PropertyResource::make($property->load('units'));
    }

    public function tenancies(Request $request): AnonymousResourceCollection
    {
        $this->authorize('estate.view');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'ended'])],
            'tenant_contact_id' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tenancies = Tenancy::query()
            ->with('unit')
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['tenant_contact_id']), fn ($q) => $q->where('tenant_contact_id', $filters['tenant_contact_id']))
            ->latest('moved_in_on')
            ->paginate($filters['per_page'] ?? 25);

        return TenancyResource::collection($tenancies);
    }

    public function showTenancy(Tenancy $tenancy): TenancyResource
    {
        $this->authorize('estate.view');

        return TenancyResource::make($tenancy->load('unit'));
    }
}
