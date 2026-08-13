<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\DealResource;
use App\Models\Deal;
use App\Services\DealPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The pipeline over HTTP.
 *
 * Every action goes through DealPipeline — the same service the Livewire board
 * calls — so the API cannot drift into its own idea of what winning a deal
 * does. Authorisation is the ordinary policy layer: a token can never do more
 * than its user could do signed in.
 */
class DealController extends ApiController
{
    public function __construct(private readonly DealPipeline $pipeline) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Deal::class);

        $filters = $request->validate([
            'stage' => ['sometimes', Rule::in(array_keys(Deal::STAGES))],
            'open' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $deals = Deal::query()
            ->when(isset($filters['stage']), fn ($q) => $q->stage($filters['stage']))
            ->when($request->boolean('open'), fn ($q) => $q->open())
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return DealResource::collection($deals);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Deal::class);

        $data = $request->validate($this->rules());

        $deal = $this->pipeline->create($data, $request->user());

        return DealResource::make($deal)->response()->setStatusCode(201);
    }

    public function show(Deal $deal): DealResource
    {
        $this->authorize('view', $deal);

        return DealResource::make($deal);
    }

    public function update(Request $request, Deal $deal): DealResource
    {
        $this->authorize('update', $deal);

        $data = $request->validate($this->rules(updating: true));

        return DealResource::make($this->pipeline->update($deal, $data));
    }

    /**
     * Moving a deal is its own endpoint rather than a field on update: it is
     * the action a board actually performs, and it carries a rule (a lost deal
     * wants a reason) that a general update has no business enforcing.
     */
    public function move(Request $request, Deal $deal): DealResource
    {
        $this->authorize('update', $deal);

        $data = $request->validate([
            'stage' => ['required', Rule::in(array_keys(Deal::STAGES))],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);

        return DealResource::make(
            $this->pipeline->moveTo($deal, $data['stage'], $data['lost_reason'] ?? null)
        );
    }

    /**
     * Turn a won deal into a draft invoice.
     *
     * Needs the permission to create an invoice, not merely to edit a deal:
     * this writes into the sales module, and a junior chasing deals is often
     * deliberately not the person who bills for them.
     */
    public function invoice(Request $request, Deal $deal): JsonResponse
    {
        $this->authorize('update', $deal);
        $this->authorize('sales.create');

        try {
            $document = $this->pipeline->convertToInvoice($deal, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'document_id' => $document->id,
                'status' => $document->status->value,
                'total' => (float) $document->total,
                'deal' => DealResource::make($deal->fresh()),
            ],
        ], 201);
    }

    public function destroy(Deal $deal): JsonResponse
    {
        $this->authorize('delete', $deal);

        $deal->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:200'],
            /*
             * One of the two must be present: a deal is about somebody, but
             * that somebody is often a name and a number before they are a
             * customer record. Enforced here as well as in the service so the
             * caller gets a 422 naming the field rather than a 500.
             */
            'contact_id' => [$updating ? 'nullable' : 'required_without:lead_name', 'nullable', 'string', 'exists:contacts,id'],
            'lead_name' => [$updating ? 'nullable' : 'required_without:contact_id', 'nullable', 'string', 'max:150'],
            'lead_phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'stage' => ['nullable', Rule::in(array_keys(Deal::STAGES))],
            'lost_reason' => ['nullable', 'string', 'max:255'],
            'expected_close_on' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
