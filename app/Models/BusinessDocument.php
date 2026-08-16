<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use App\Services\Documents\DocumentVersioner;
use App\Support\DocumentKinds;
use App\Support\DocumentTemplates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    use Approvable;
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'tags' => 'array',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'expires_on' => 'date',
        ];
    }

    /** Never throws — a renamed kind must not lose a row from a list. */
    public function kindLabel(): string
    {
        return DocumentKinds::label($this->kind);
    }

    /**
     * Confidential and above. Used by the policy, never by a view: hiding a
     * link is not access control.
     */
    public function isConfidential(): bool
    {
        return DocumentKinds::securityRank($this->security)
            >= DocumentKinds::securityRank('confidential');
    }

    /**
     * The top classification, and the only one that narrows who may read the
     * document. Confidential marks a document; restricted closes it.
     *
     * The distinction is deliberate. A level that only its author can open is
     * useless to a business that has to work on the thing, so making every
     * sensitive document restricted would only teach people to stop marking
     * them. See BusinessDocumentPolicy, which is where this is enforced.
     */
    public function isRestricted(): bool
    {
        return DocumentKinds::securityRank($this->security)
            >= DocumentKinds::securityRank('restricted');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * What an automation rule may set here — filing fields only, the same set
     * already allow-listed for an issued document's booted() guard. Never
     * title, body or recipient: those are content, and a settings screen must
     * not be able to rewrite what a document says.
     *
     * @return array<int, string>
     */
    public function automatableFields(): array
    {
        return ['kind', 'description', 'security', 'tags', 'folder_id', 'department_id', 'owner_id', 'expires_on'];
    }

    /** Which part of the business the document is filed under, not who wrote it. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Documents that will lapse soon.
     *
     * Already-expired ones are excluded on purpose: they belong on a different
     * list. "Expiring" is a prompt to act before a deadline, and mixing in the
     * deadlines already missed makes the list something people stop opening.
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', now()->toDateString())
            ->whereDate('expires_on', '<=', now()->addDays($days)->toDateString());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', now()->toDateString());
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * Excludes restricted documents this user may not open.
     *
     * The same rule `BusinessDocumentPolicy::readable()` enforces per-record —
     * open to the owner and to holders of `papers.manage`, closed to everyone
     * else — expressed as a query so a list can apply it up front rather than
     * fetching every restricted row just to filter it out in PHP. A workspace
     * that merely left a restricted document out of a list would have hidden
     * it, which is not the same as refusing it: the policy is still the
     * authority on whether its URL, its API route or its id can reach it.
     */
    public function scopeReadableBy(Builder $query, User $user, bool $mayManage): Builder
    {
        if ($mayManage) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->whereNull('security')
                ->orWhere('security', '!=', 'restricted')
                ->orWhere('owner_id', $user->id);
        });
    }

    protected static function booted(): void
    {
        static::updating(function (BusinessDocument $document) {
            if ($document->getOriginal('status') !== 'issued') {
                return;
            }

            /*
             * Voiding is the one thing an issued document may still undergo,
             * so the columns that record it are permitted. Its content is not.
             *
             * Filing is permitted too, and the distinction is worth stating:
             * putting a signed contract in a folder, tagging it, handing it to
             * a new owner or noting when it expires does not change the
             * document — it changes where the business keeps it. None of these
             * columns appear in canonicalPayload(), so an issued document
             * survives every one of them with its hash still valid, and there
             * is a test asserting exactly that.
             *
             * Refusing them would make the module useless for the documents
             * that most need managing: the issued ones.
             */
            $mutable = [
                'status', 'updated_at', 'deleted_at', 'verification_token_id',
                'voided_at', 'voided_by', 'void_reason',
                'kind', 'description', 'security', 'language', 'tags',
                'owner_id', 'expires_on', 'folder_id', 'department_id', 'is_locked',
                'signature_mode',
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

        /*
         * A version on creation and on every content change. Filing a
         * document — moving it, tagging it, locking it — is not content, and
         * must not flood the history with a version identical to the one
         * before it. See DocumentVersioner for exactly which columns count.
         */
        static::created(fn (BusinessDocument $document) => app(DocumentVersioner::class)->snapshotInitial($document));
        static::updated(fn (BusinessDocument $document) => app(DocumentVersioner::class)->snapshotIfChanged($document));
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BusinessDocumentVersion::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(BusinessDocumentComment::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(BusinessDocumentSignature::class)->orderBy('order');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(BusinessDocumentShare::class);
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
