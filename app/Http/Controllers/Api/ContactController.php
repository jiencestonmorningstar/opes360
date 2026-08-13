<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ContactResource;
use App\Livewire\Customers\Form as CustomerForm;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The customer and supplier book over HTTP.
 *
 * Field mapping comes from CustomerForm::attributesFrom(), which the screen and
 * the offline sync engine already share. A third copy here is how a contact
 * created over the API ends up with its address in a different shape from one
 * typed into the form.
 */
class ContactController extends ApiController
{
    private const TYPES = ['customer', 'supplier', 'vendor', 'lead'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contact::class);

        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $contacts = Contact::query()
            ->when(isset($filters['type']), fn (Builder $q) => $q->where('type', $filters['type']))
            ->when(isset($filters['q']), function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('company_name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);

        return ContactResource::collection($contacts);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Contact::class);

        $data = $request->validate($this->rules());

        $contact = Contact::create(
            CustomerForm::attributesFrom($data)
            + ['type' => $data['type'] ?? 'customer', 'created_by' => $request->user()->id]
        );

        return ContactResource::make($contact)->response()->setStatusCode(201);
    }

    public function show(Contact $contact): ContactResource
    {
        $this->authorize('view', $contact);

        return ContactResource::make($contact);
    }

    public function update(Request $request, Contact $contact): ContactResource
    {
        $this->authorize('update', $contact);

        $data = $request->validate($this->rules(updating: true));

        // Merged over what is already stored, so a partial update does not
        // blank the fields it did not mention.
        $contact->update(CustomerForm::attributesFrom($data + $this->currentFormShape($contact)));

        if (isset($data['type'])) {
            $contact->update(['type' => $data['type']]);
        }

        return ContactResource::make($contact->fresh());
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);

        $contact->delete();

        return response()->json(null, 204);
    }

    /**
     * The stored record in the shape attributesFrom() expects, so an update
     * that names three fields keeps the rest.
     *
     * @return array<string, mixed>
     */
    private function currentFormShape(Contact $contact): array
    {
        return [
            'name' => $contact->name,
            'company_name' => $contact->company_name,
            'email' => $contact->email,
            'phone' => data_get($contact->phones, 0, ''),
            'whatsapp' => $contact->whatsapp,
            'street' => data_get($contact->address, 'street', ''),
            'city' => data_get($contact->address, 'city', ''),
            'country' => data_get($contact->address, 'country', ''),
            'tax_id' => $contact->tax_id,
            'payment_terms_days' => $contact->payment_terms_days,
            'notes' => $contact->notes,
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $updating = false): array
    {
        return [
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:160'],
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'company_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'street' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'tax_id' => ['nullable', 'string', 'max:60'],
            'payment_terms_days' => ['nullable', 'numeric', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
