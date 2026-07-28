<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

class Document extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;
    use Syncable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'valid_until' => 'date',
            'issued_at' => 'datetime',
            'synced_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Enforces the immutability rule from docs/architecture/decisions.md #6 at
         * the model layer, so it holds for sync writes and console commands too —
         * not only for requests that happen to go through a form.
         */
        static::updating(function (Document $document) {
            $original = $document->getOriginal('status');
            $originalStatus = $original instanceof DocumentStatus
                ? $original
                : DocumentStatus::tryFrom((string) $original);

            if ($originalStatus === null || ! $originalStatus->isImmutable()) {
                return;
            }

            /*
             * The permitted set is bookkeeping, not content: payment state, and
             * the replication metadata that says where this row sits in the
             * change stream. `sync_sequence` in particular has to be here — a
             * paid invoice must still reach the devices that need to know it was
             * paid, and that means its position in the stream moves even though
             * nothing about the document itself has.
             */
            $mutable = [
                'status', 'amount_paid', 'balance', 'pdf_path', 'synced_at',
                'updated_at', 'deleted_at', 'verification_token_id', 'signature_path',
                'sync_sequence', 'device_id',
            ];

            $illegal = array_diff(array_keys($document->getDirty()), $mutable);

            if ($illegal !== []) {
                throw new RuntimeException(sprintf(
                    'Document %s is %s and cannot be edited (attempted: %s). Void and reissue, or raise a credit note.',
                    $document->number ?? $document->id,
                    $originalStatus->value,
                    implode(', ', $illegal)
                ));
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('sort_order');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(DocumentApproval::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    public function verificationToken(): BelongsTo
    {
        return $this->belongsTo(VerificationToken::class);
    }

    public function scopeOfType(Builder $query, DocumentType|string $type): Builder
    {
        return $query->where('type', $type instanceof DocumentType ? $type->value : $type);
    }

    public function scopeInvoices(Builder $query): Builder
    {
        return $query->ofType(DocumentType::Invoice);
    }

    /** Issued and still owed money — drives the Outstanding stat card. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DocumentStatus::Issued->value,
            DocumentStatus::Sent->value,
            DocumentStatus::Accepted->value,
            DocumentStatus::Partial->value,
        ])->where('balance', '>', 0);
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            DocumentStatus::Draft->value,
            DocumentStatus::Void->value,
        ]);
    }

    /**
     * Date bounds are widened to cover the whole first and last day. Eloquent
     * stores date casts with a zeroed time component, so a bare `Y-m-d` upper
     * bound would exclude every row on that final day.
     */
    public function scopeIssuedBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->issued()->whereBetween('issue_date', [
            CarbonImmutable::parse($from)->startOfDay(),
            CarbonImmutable::parse($to)->endOfDay(),
        ]);
    }

    /**
     * Canonical representation hashed at issue for tamper detection. Deliberately
     * excludes anything that legitimately changes afterwards (payment state, PDF
     * path, sync metadata) so a genuine mismatch always means tampering.
     */
    public function canonicalPayload(): string
    {
        return json_encode([
            'type' => $this->type->value,
            'number' => $this->number,
            'company_id' => $this->company_id,
            'contact_id' => $this->contact_id,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'subtotal' => (string) $this->subtotal,
            'discount_total' => (string) $this->discount_total,
            'tax_total' => (string) $this->tax_total,
            'total' => (string) $this->total,
            'lines' => $this->lines->map(fn (DocumentLine $line) => [
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'tax_amount' => (string) $line->tax_amount,
                'line_total' => (string) $line->line_total,
            ])->all(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Payment state for the badge shown in lists.
     *
     * Distinct from `status`, which tracks the document's lifecycle (draft, sent,
     * accepted…). What a user scanning a list wants to know is whether the money
     * arrived, so "sent but unpaid" reads as Pending rather than Sent.
     *
     * @return array{label: string, tone: string}
     */
    public function paymentState(): array
    {
        if ($this->status === DocumentStatus::Void) {
            return ['label' => 'Void', 'tone' => 'muted'];
        }

        if ($this->status === DocumentStatus::Draft) {
            return ['label' => 'Draft', 'tone' => 'neutral'];
        }

        if ((float) $this->balance <= 0.0) {
            return ['label' => 'Paid', 'tone' => 'positive'];
        }

        if ($this->due_date !== null && $this->due_date->isPast()) {
            return ['label' => 'Overdue', 'tone' => 'negative'];
        }

        if ((float) $this->amount_paid > 0.0) {
            return ['label' => 'Partial', 'tone' => 'warning'];
        }

        return ['label' => 'Pending', 'tone' => 'warning'];
    }

    public function isTampered(): bool
    {
        if (blank($this->content_hash)) {
            return false;
        }

        return ! hash_equals($this->content_hash, hash('sha256', $this->canonicalPayload()));
    }
}
