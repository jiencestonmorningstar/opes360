<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BusinessDocumentResource;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentComment;
use App\Models\BusinessDocumentShare;
use App\Models\BusinessDocumentSignature;
use App\Models\BusinessDocumentVersion;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Services\Documents\DocumentActivity;
use App\Services\Documents\DocumentComments;
use App\Services\Documents\DocumentLinker;
use App\Services\Documents\DocumentRetention;
use App\Services\Documents\DocumentSharing;
use App\Services\Documents\DocumentSignatureRequests;
use App\Services\Documents\DocumentVersioner;
use App\Services\Documents\VersionComparator;
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

    public function __construct(
        private readonly DocumentLinker $linker,
        private readonly DocumentVersioner $versioner,
        private readonly VersionComparator $comparator,
        private readonly DocumentComments $comments,
        private readonly DocumentActivity $activity,
        private readonly DocumentSignatureRequests $signatureRequests,
        private readonly DocumentSharing $sharing,
        private readonly DocumentRetention $retention,
    ) {}

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

    public function versions(BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json(['data' => $document->versions()
            ->orderBy('version_number')
            ->get()
            ->map(fn (BusinessDocumentVersion $v) => [
                'id' => $v->id,
                'version' => $v->version_number,
                'title' => $v->title,
                'created_by' => $v->created_by,
                'created_at' => $v->created_at?->toIso8601String(),
            ])]);
    }

    /** Diff between two versions of the same document. Word-level, per field. */
    public function compareVersions(Request $request, BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $data = $request->validate([
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'min:1'],
        ]);

        $from = $document->versions()->where('version_number', $data['from'])->firstOrFail();
        $to = $document->versions()->where('version_number', $data['to'])->firstOrFail();

        return response()->json(['data' => $this->comparator->compare($from, $to)]);
    }

    /** Only a draft can be restored — the same rule the service itself enforces. */
    public function restoreVersion(BusinessDocument $document, BusinessDocumentVersion $version): JsonResponse
    {
        $this->authorize('update', $document);

        abort_unless($version->business_document_id === $document->id, 404);

        try {
            $restored = $this->versioner->restore($document, $version, request()->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => BusinessDocumentResource::make($restored)]);
    }

    public function activity(BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $timeline = $this->activity->timeline($document)->map(fn ($entry) => [
            'type' => $entry['type'],
            'summary' => $entry['summary'],
            'actor' => $entry['actor'],
            'at' => $entry['at']?->toIso8601String(),
        ]);

        return response()->json(['data' => $timeline]);
    }

    public function signatureStatus(BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json(['data' => $this->signatureRequests->status($document) + [
            'signers' => $document->signatures->map(fn (BusinessDocumentSignature $s) => [
                'id' => $s->id,
                'name' => $s->signer_name,
                'email' => $s->signer_email,
                'order' => $s->order,
                'status' => $s->status,
                'signed_at' => $s->signed_at?->toIso8601String(),
            ]),
        ]]);
    }

    public function requestSignatures(Request $request, BusinessDocument $document): JsonResponse
    {
        $this->authorize('share', $document);

        $data = $request->validate([
            'signers' => ['required', 'array', 'min:1'],
            'signers.*.name' => ['required', 'string', 'max:200'],
            'signers.*.email' => ['required', 'email', 'max:200'],
            'mode' => ['sometimes', 'string', Rule::in(DocumentSignatureRequests::MODES)],
        ]);

        try {
            $signatures = $this->signatureRequests->request($document, $data['signers'], $data['mode'] ?? 'parallel');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $signatures->map(fn (BusinessDocumentSignature $s) => [
            'id' => $s->id,
            'name' => $s->signer_name,
            'order' => $s->order,
        ])], 201);
    }

    public function shares(BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json(['data' => $document->shares->map(fn (BusinessDocumentShare $s) => $this->sharePayload($s))]);
    }

    public function createShare(Request $request, BusinessDocument $document): JsonResponse
    {
        $this->authorize('share', $document);

        $data = $request->validate([
            'expires_at' => ['nullable', 'date', 'after:now'],
            'password' => ['nullable', 'string', 'min:4', 'max:100'],
            'allow_download' => ['sometimes', 'boolean'],
        ]);

        $share = $this->sharing->create(
            $document,
            $request->user(),
            isset($data['expires_at']) ? \Illuminate\Support\Carbon::parse($data['expires_at']) : null,
            $data['password'] ?? null,
            $data['allow_download'] ?? true,
        );

        return response()->json(['data' => $this->sharePayload($share) + [
            'url' => route('shares.show', $share->share_token),
        ]], 201);
    }

    public function revokeShare(BusinessDocumentShare $share): JsonResponse
    {
        $this->authorize('share', $share->document);

        $this->sharing->revoke($share);

        return response()->json(['data' => $this->sharePayload($share->fresh())]);
    }

    /** @return array<string, mixed> */
    protected function sharePayload(BusinessDocumentShare $share): array
    {
        return [
            'id' => $share->id,
            'expires_at' => $share->expires_at?->toIso8601String(),
            'password_protected' => $share->isPasswordProtected(),
            'allow_download' => $share->allow_download,
            'revoked_at' => $share->revoked_at?->toIso8601String(),
            'is_live' => $share->isLive(),
            'views' => $share->accesses()->count(),
            'created_at' => $share->created_at?->toIso8601String(),
        ];
    }

    public function retention(BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json(['data' => [
            'legal_hold' => $document->isUnderLegalHold(),
            'legal_hold_reason' => $document->legal_hold_reason,
            'retain_until' => $this->retention->retainUntil($document)?->toDateString(),
            'is_disposable' => $this->retention->isDisposable($document),
            'lifecycle' => $document->lifecycleLabel(),
        ]]);
    }

    public function placeLegalHold(Request $request, BusinessDocument $document): JsonResponse
    {
        $this->authorize('manage', $document);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $held = $this->retention->placeLegalHold($document, $data['reason'], $request->user());

        return response()->json(['data' => [
            'legal_hold' => true,
            'legal_hold_reason' => $held->legal_hold_reason,
        ]]);
    }

    public function liftLegalHold(BusinessDocument $document): JsonResponse
    {
        $this->authorize('manage', $document);

        $this->retention->liftLegalHold($document);

        return response()->json(['data' => ['legal_hold' => false]]);
    }

    public function dispose(BusinessDocument $document): JsonResponse
    {
        $this->authorize('manage', $document);

        try {
            $this->retention->dispose($document);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    public function comments(BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $comments = $document->comments()->topLevel()->with('replies.author', 'author')->get();

        return response()->json(['data' => $comments->map(fn ($c) => $this->commentPayload($c))]);
    }

    public function postComment(Request $request, BusinessDocument $document): JsonResponse
    {
        $this->authorize('view', $document);
        $this->authorize('create', BusinessDocumentComment::class);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'string', 'exists:business_document_comments,id'],
            'mentioned_user_ids' => ['nullable', 'array'],
            'mentioned_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $parent = isset($data['parent_id']) ? BusinessDocumentComment::findOrFail($data['parent_id']) : null;

        try {
            $comment = $this->comments->post(
                $document,
                $request->user(),
                $data['body'],
                $parent,
                $data['mentioned_user_ids'] ?? [],
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->commentPayload($comment)], 201);
    }

    public function resolveComment(BusinessDocumentComment $comment): JsonResponse
    {
        $this->authorize('resolve', $comment);

        return response()->json(['data' => $this->commentPayload($this->comments->resolve($comment, request()->user()))]);
    }

    public function reopenComment(BusinessDocumentComment $comment): JsonResponse
    {
        $this->authorize('resolve', $comment);

        return response()->json(['data' => $this->commentPayload($this->comments->reopen($comment))]);
    }

    public function destroyComment(BusinessDocumentComment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    protected function commentPayload(BusinessDocumentComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'author' => $comment->author->name,
            'author_id' => $comment->user_id,
            'mentioned_user_ids' => $comment->mentioned_user_ids ?? [],
            'resolved_at' => $comment->resolved_at?->toIso8601String(),
            'created_at' => $comment->created_at?->toIso8601String(),
            'replies' => $comment->relationLoaded('replies')
                ? $comment->replies->map(fn ($r) => $this->commentPayload($r))->values()
                : [],
        ];
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
