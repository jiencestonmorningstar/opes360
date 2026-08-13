<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Http\Resources\DocumentResource;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Support\CurrentCompany;
use App\Support\Vat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Quotations, invoices and the rest, over HTTP.
 *
 * Two rules shape this controller, and both come from the product rather than
 * from REST convention:
 *
 *   A document is created as a draft and issued as a separate act. Issuing is
 *   the moment it becomes immutable, gets its number and enters the books, so
 *   it goes through DocumentIssuer — the one path that exists for it — rather
 *   than being a status a caller may PATCH.
 *
 *   An issued document cannot be edited or deleted at all. That is the whole
 *   point of issuing it; a customer holding a printed invoice must be able to
 *   trust that the copy on file still says what theirs does.
 *
 * TVA is computed by Vat::forCompany(), the same pass the screen uses, so the
 * tax on an API-created invoice matches the tax on a typed one to the franc.
 */
class DocumentController extends ApiController
{
    public function __construct(
        private readonly DocumentIssuer $issuer,
        private readonly DocumentConverter $converter,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);

        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(array_column(DocumentType::cases(), 'value'))],
            'status' => ['sometimes', Rule::in(array_column(DocumentStatus::cases(), 'value'))],
            'contact_id' => ['sometimes', 'string'],
            'outstanding' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $documents = Document::query()
            ->with('contact')
            ->when(isset($filters['type']), fn (Builder $q) => $q->ofType($filters['type']))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['contact_id']), fn (Builder $q) => $q->where('contact_id', $filters['contact_id']))
            ->when($request->boolean('outstanding'), fn (Builder $q) => $q->outstanding())
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return DocumentResource::collection($documents);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_column(DocumentType::cases(), 'value'))],
            'contact_id' => ['required', 'string', 'exists:contacts,id'],
            'issue_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.item_id' => ['nullable', 'string', 'exists:items,id'],
            'lines.*.unit' => ['nullable', 'string', 'max:30'],
            // Issuing on creation is offered because a till has no use for a
            // draft it must then issue in a second call.
            'issue' => ['sometimes', 'boolean'],
        ]);

        $contact = Contact::findOrFail($data['contact_id']);
        $company = app(CurrentCompany::class)->get();

        $vat = Vat::forCompany($company, $data['lines']);

        $document = DB::transaction(function () use ($data, $contact, $company, $vat) {
            $document = Document::create([
                'type' => $data['type'],
                'contact_id' => $contact->id,
                'status' => DocumentStatus::Draft,
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? null,
                'currency' => $company->currency,
                'subtotal' => $vat['subtotal'],
                'tax_total' => $vat['tax_total'],
                'total' => $vat['total'],
                'amount_paid' => 0,
                'balance' => $vat['total'],
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'reference' => $data['reference'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($data['lines'] as $index => $line) {
                DocumentLine::create([
                    'document_id' => $document->id,
                    'item_id' => $line['item_id'] ?? null,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'] ?? 'unit',
                    'unit_price' => $line['unit_price'],
                    'tax_amount' => $vat['lines'][$index]['tax'] ?? 0.0,
                    'line_total' => $vat['lines'][$index]['net'] ?? 0.0,
                    'sort_order' => $index,
                ]);
            }

            return $document;
        });

        if ($request->boolean('issue')) {
            $this->authorize('sales.issue');
            $document = $this->issuer->issue($document, $request->user());
        }

        return DocumentResource::make($document->load('lines', 'contact'))
            ->response()->setStatusCode(201);
    }

    public function show(Document $document): DocumentResource
    {
        $this->authorize('view', $document);

        return DocumentResource::make($document->load('lines', 'contact'));
    }

    /**
     * Issuing is its own endpoint because it is its own act: the document gets
     * its number, its hash and its verification token, and becomes immutable.
     */
    public function issue(Request $request, Document $document): DocumentResource|JsonResponse
    {
        $this->authorize('update', $document);
        $this->authorize('sales.issue');

        try {
            $issued = $this->issuer->issue($document, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return DocumentResource::make($issued->load('lines', 'contact'));
    }

    /**
     * Cancel an issued document.
     *
     * The counterpart to the missing update route: an issued document cannot
     * be edited, so cancelling it is how a mistake is undone. Voided rather
     * than deleted, its verification token revoked so a printed copy stops
     * verifying, and its ledger entry reversed rather than removed — March
     * still has an answer.
     *
     * Refused while payments sit against it: money already taken has to be
     * dealt with first, and silently detaching it would leave a receipt
     * pointing at a document that says nothing was ever owed.
     */
    public function void(Request $request, Document $document): DocumentResource|JsonResponse
    {
        $this->authorize('void', $document);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $voided = $this->converter->void($document, $request->user(), $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return DocumentResource::make($voided->load('lines', 'contact'));
    }

    /**
     * Quotation → invoice, proforma → invoice, and the rest of the chain.
     *
     * Needs the issue right rather than merely the right to create a draft,
     * because converting produces a permanent numbered document.
     */
    public function convert(Request $request, Document $document): DocumentResource|JsonResponse
    {
        $this->authorize('convert', $document);

        try {
            $converted = $this->converter->convert($document, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return DocumentResource::make($converted->load('lines', 'contact'))
            ->response()->setStatusCode(201);
    }

    /**
     * Credit part or all of an invoice.
     *
     * The honest way to reverse a sale that has been paid for: the money is
     * acknowledged as not owed rather than the original invoice being edited
     * to pretend it was never charged.
     */
    public function creditNote(Request $request, Document $document): DocumentResource|JsonResponse
    {
        $this->authorize('convert', $document);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $note = $this->converter->creditNote(
                $document,
                $request->user(),
                (float) $data['amount'],
                $data['reason'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return DocumentResource::make($note->load('lines', 'contact'))
            ->response()->setStatusCode(201);
    }

    /**
     * Only a draft. An issued document is what a customer is holding a printed
     * copy of, and the two must not be able to disagree.
     */
    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        if ($document->status !== DocumentStatus::Draft) {
            return response()->json([
                'message' => 'An issued document cannot be deleted. Void it instead.',
            ], 422);
        }

        $document->delete();

        return response()->json(null, 204);
    }
}
