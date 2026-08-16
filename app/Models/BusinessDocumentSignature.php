<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\UniqueId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person's part of a signing round.
 *
 * The signing link's token is deliberately not the document's public
 * verification token — different audience (one named signer, not anyone with
 * the link), different lifetime (spent once, not durable), different
 * question ("did you sign this" versus "is this genuine").
 */
class BusinessDocumentSignature extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'business_document_id');
    }

    public function signerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    /** 32 chars of base62 — a link, not a password, but not guessable either. */
    public static function newSigningToken(): string
    {
        return UniqueId::make(
            fn () => Str::random(32),
            fn (string $token) => static::query()->withoutGlobalScopes()->where('signing_token', $token)->exists(),
        );
    }
}
