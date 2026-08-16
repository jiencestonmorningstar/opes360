<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BusinessDocumentResource;
use App\Models\BusinessDocument;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Services\Documents\DocumentLinker;
use App\Support\DocumentKinds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The Library over HTTP — Documents plan phase 2.2, `docs/superpowers/plans/2026-08-15-documents-core.md`.
 *
 * Filing metadata only. Uploading a file and generating one from a template
 * both need a browser round-trip this endpoint does not attempt to replace;
 * an integration that already has a document elsewhere links to it here.
 */
class LibraryController extends ApiController
{
    /**
     * Which ERP records a document may be linked to, and how a token names
     * one. A closed list rather than accepting an arbitrary class name from
     * the request — that would let a caller attach a document to any model in
     * the application, including ones with no tenant scope at all.
     */
    protected const RELATABLE = [
        'contact' => Contact::class,
        'employee' => Employee::class,
        'document' => Document::class,
        'project' => Project::class,
    ];

    public function __construct(private readonly DocumentLinker $linker) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('papers.view');

        $filters = $request->validate([
            'kind' => ['sometimes', 'string'],
            'security' => ['sometimes', 'string', Rule::in(array_keys(DocumentKinds::securityLevels()))],
            'folder_id' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'issued', 'void'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $mayManage = $request->user()->can('papers.manage');

        $documents = BusinessDocument::query()
            ->readableBy($request->user(), $mayManage)
            ->when(isset($filters['kind']), fn ($q) => $q->ofKind($filters['kind']))
            ->when(isset($filters['security']), fn ($q) => $q->where('security', $filters['security']))
            ->when(isset($filters['folder_id']), fn ($q) => $q->where('folder_id', $filters['folder_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 25);

        return BusinessDocumentResource::collection($documents);
    }

    public function show(BusinessDocument $document): BusinessDocumentResource
    {
        $this->authorize('view', $document);

        return BusinessDocumentResource::make($document);
    }

    /**
     * Compose a document from a template. Uploading a file is a browser
     * action — see the Papers screen — and stays off this endpoint.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('papers.create');

        $data = $request->validate([
            'template' => ['required', 'string'],
            'title' => ['required', 'string', 'max:200'],
            'recipient' => ['nullable', 'string', 'max:200'],
            'kind' => ['nullable', 'string', Rule::in(DocumentKinds::keys())],
            'security' => ['nullable', 'string', Rule::in(array_keys(DocumentKinds::securityLevels()))],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:60'],
            'fields' => ['nullable', 'array'],
            'body' => ['nullable', 'string'],
        ]);

        $document = BusinessDocument::create($data + [
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return BusinessDocumentResource::make($document)->response()->setStatusCode(201);
    }

    /**
     * Filing fields only: kind, tags, folder, owner, security, expiry. Never
     * title, body or recipient — that is content, and content on an issued
     * document is frozen by the model regardless of what this endpoint
     * accepts. See BusinessDocument::booted() for the enforcement.
     */
    public function update(Request $request, BusinessDocument $document): BusinessDocumentResource
    {
        $this->authorize('file', $document);

        $data = $request->validate([
            'kind' => ['sometimes', 'nullable', 'string', Rule::in(DocumentKinds::keys())],
            'security' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(DocumentKinds::securityLevels()))],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:60'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'exists:business_document_folders,id'],
            'department_id' => ['sometimes', 'nullable', 'string', 'exists:departments,id'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $document->update($data);

        return BusinessDocumentResource::make($document->fresh());
    }

    public function destroy(BusinessDocument $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $document->delete();

        return response()->json(null, 204);
    }

    /**
     * Attach a document to the ERP record it is about.
     *
     * Not `authorize('manage', $document)` — the same reasoning as the
     * `share` ability: filing a document against a customer is a document
     * action, not the confidentiality key. `papers.share` covers it because
     * both send the document somewhere beyond a straightforward read.
     */
    public function attachRelation(Request $request, BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);
        $this->authorize('papers.share');

        $data = $request->validate([
            'related_type' => ['required', 'string', Rule::in(array_keys(self::RELATABLE))],
            'related_id' => ['required', 'string'],
            'role' => ['sometimes', 'string'],
        ]);

        $related = self::RELATABLE[$data['related_type']]::query()->findOrFail($data['related_id']);

        $relation = $this->linker->attach($document, $related, $data['role'] ?? 'about', $request->user());

        return response()->json(['data' => [
            'id' => $relation->id,
            'related_type' => $data['related_type'],
            'related_id' => $relation->related_id,
            'role' => $relation->role,
        ]], 201);
    }

    public function detachRelation(Request $request, BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);
        $this->authorize('papers.share');

        $data = $request->validate([
            'related_type' => ['required', 'string', Rule::in(array_keys(self::RELATABLE))],
            'related_id' => ['required', 'string'],
            'role' => ['sometimes', 'string'],
        ]);

        $related = self::RELATABLE[$data['related_type']]::query()->findOrFail($data['related_id']);

        $this->linker->detach($document, $related, $data['role'] ?? null);

        return response()->json(null, 204);
    }
}
