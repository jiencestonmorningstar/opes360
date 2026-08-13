<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Resources\PaymentResource;
use App\Models\Document;
use App\Models\Payment;
use App\Services\PaymentRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Money coming in.
 *
 * Recording goes through PaymentRecorder, which does the whole thing in one
 * transaction — the payment, its allocation, the document's balance and
 * status, the numbered receipt with its hash and verification token, and the
 * customer's cached balance. Writing a Payment row directly from here would
 * produce money the books never saw and a receipt nobody can verify.
 *
 * There is no update route. A payment is a thing that happened; correcting it
 * is a refund or a void, which are their own acts with their own trail.
 */
class PaymentController extends ApiController
{
    public function __construct(private readonly PaymentRecorder $recorder) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->validate([
            'contact_id' => ['sometimes', 'string'],
            'method' => ['sometimes', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = Payment::query()
            ->with('receipt')
            ->when(isset($filters['contact_id']), fn (Builder $q) => $q->where('contact_id', $filters['contact_id']))
            ->when(isset($filters['method']), fn (Builder $q) => $q->where('method', $filters['method']))
            ->when(isset($filters['from']), fn (Builder $q) => $q->whereDate('received_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->whereDate('received_at', '<=', $filters['to']))
            ->latest('received_at')
            ->paginate($filters['per_page'] ?? 25);

        return PaymentResource::collection($payments);
    }

    /**
     * Record a payment against an issued document.
     *
     * `received_at` is accepted because a business enters last week's cash on
     * Monday, and the receipt's content hash covers it — stamping now and
     * correcting the date later produces a receipt that fails its own QR
     * verification.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('payments.record');

        $data = $request->validate([
            'document_id' => ['required', 'string', 'exists:documents,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'reference' => ['nullable', 'string', 'max:120'],
            'received_at' => ['nullable', 'date'],
            'receipt_format' => ['sometimes', 'string', 'max:30'],
        ]);

        $document = Document::findOrFail($data['document_id']);

        $this->authorize('view', $document);

        try {
            $payment = $this->recorder->record(
                document: $document,
                cashier: $request->user(),
                amount: (float) $data['amount'],
                method: PaymentMethod::from($data['method']),
                reference: $data['reference'] ?? null,
                receiptFormat: $data['receipt_format'] ?? 'thermal80',
                receivedAt: isset($data['received_at']) ? now()->parse($data['received_at']) : null,
            );
        } catch (RuntimeException $e) {
            // Overpayment, a draft or voided document, a zero amount — all
            // refusals of the domain rather than of the request's shape.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return PaymentResource::make($payment->load('receipt.verificationToken', 'allocations'))
            ->response()->setStatusCode(201);
    }

    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return PaymentResource::make($payment->load('receipt.verificationToken', 'allocations'));
    }
}
