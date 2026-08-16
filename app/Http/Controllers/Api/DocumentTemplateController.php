<?php

namespace App\Http\Controllers\Api;

use App\Models\BusinessDocumentTemplate;
use App\Services\Documents\CustomDocumentTemplates;
use App\Support\DocumentKinds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A business's own templates — §8. The built-in catalogue in
 * App\Support\DocumentTemplates has no endpoint of its own; it is code,
 * shipped and reviewed like any other, and is already reachable through the
 * Papers gallery. This is only for what a business writes for itself.
 */
class DocumentTemplateController extends ApiController
{
    public function __construct(private readonly CustomDocumentTemplates $templates) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', BusinessDocumentTemplate::class);

        return response()->json(['data' => BusinessDocumentTemplate::query()
            ->get()
            ->map(fn (BusinessDocumentTemplate $t) => $this->payload($t))]);
    }

    public function show(BusinessDocumentTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        return response()->json(['data' => $this->payload($template) + ['body' => $template->body]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', BusinessDocumentTemplate::class);

        $data = $request->validate($this->rules());

        try {
            $template = $this->templates->create($data, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($template)], 201);
    }

    public function update(Request $request, BusinessDocumentTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $data = $request->validate($this->rules(updating: true));

        $template = $this->templates->update($template, $data);

        return response()->json(['data' => $this->payload($template)]);
    }

    public function publish(BusinessDocumentTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        return response()->json(['data' => $this->payload($this->templates->publish($template))]);
    }

    public function unpublish(BusinessDocumentTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        return response()->json(['data' => $this->payload($this->templates->unpublish($template))]);
    }

    public function versions(BusinessDocumentTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        return response()->json(['data' => $template->versions()
            ->orderBy('version_number')
            ->get()
            ->map(fn ($v) => [
                'version' => $v->version_number,
                'name' => $v->name,
                'created_by' => $v->created_by,
                'created_at' => $v->created_at?->toIso8601String(),
            ])]);
    }

    public function destroy(BusinessDocumentTemplate $template): JsonResponse
    {
        $this->authorize('delete', $template);

        $template->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'key' => [$updating ? 'prohibited' : 'required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'name' => [$required, 'string', 'max:150'],
            'summary' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:40'],
            'accent' => ['nullable', 'string', 'max:40'],
            'binding' => ['sometimes', 'boolean'],
            'fields' => ['sometimes', 'array'],
            'fields.*.key' => ['required_with:fields', 'string'],
            'fields.*.label' => ['required_with:fields', 'string'],
            'body' => [$required, 'string'],
        ];
    }

    /** @return array<string, mixed> */
    protected function payload(BusinessDocumentTemplate $template): array
    {
        return [
            'id' => $template->id,
            'key' => $template->key,
            'name' => $template->name,
            'summary' => $template->summary,
            'icon' => $template->icon,
            'accent' => $template->accent,
            'binding' => $template->binding,
            'fields' => $template->fields,
            'is_published' => $template->is_published,
            'created_at' => $template->created_at?->toIso8601String(),
        ];
    }
}
