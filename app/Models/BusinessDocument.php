<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\DocumentTemplates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A generated business document — contract, letter, certificate, minutes.
 *
 * Immutable once issued, on the same reasoning as a sales document: the moment
 * it is signed or handed over, a copy exists outside this system, and silently
 * editing the version here would leave the two disagreeing. Revise a draft, or
 * void and reissue.
 */
class BusinessDocument extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BusinessDocument $document) {
            if ($document->getOriginal('status') !== 'issued') {
                return;
            }

            // Voiding is the one thing an issued document may still undergo,
            // so the columns that record it are permitted. Its content is not.
            $mutable = [
                'status', 'updated_at', 'deleted_at', 'verification_token_id',
                'voided_at', 'voided_by', 'void_reason',
            ];
            $illegal = array_diff(array_keys($document->getDirty()), $mutable);

            if ($illegal !== []) {
                throw new RuntimeException(sprintf(
                    '%s is issued and cannot be edited (attempted: %s). Void it and issue a replacement.',
                    $document->reference ?? $document->title,
                    implode(', ', $illegal),
                ));
            }
        });
    }

    public function verificationToken(): BelongsTo
    {
        return $this->belongsTo(VerificationToken::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', 'issued');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    /** @return array<string, mixed>|null */
    public function template(): ?array
    {
        return DocumentTemplates::find($this->template);
    }

    public function templateName(): string
    {
        return $this->template()['name'] ?? 'Document';
    }

    public function accent(): string
    {
        return $this->template()['accent'] ?? 'slate';
    }

    /**
     * Hashed at issue for tamper detection. Only the parts a reader would
     * consider "the document" — not status, which legitimately changes when it
     * is voided.
     */
    public function canonicalPayload(): string
    {
        return json_encode([
            'company_id' => $this->company_id,
            'template' => $this->template,
            'reference' => $this->reference,
            'title' => $this->title,
            'recipient' => $this->recipient,
            'body' => $this->body,
            'issued_at' => $this->issued_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
    }

    public function isTampered(): bool
    {
        return $this->content_hash !== null
            && ! hash_equals($this->content_hash, hash('sha256', $this->canonicalPayload()));
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'issued' => ['label' => 'Issued', 'tone' => 'positive'],
            'void' => ['label' => 'Void', 'tone' => 'muted'],
            default => ['label' => 'Draft', 'tone' => 'neutral'],
        };
    }
}
