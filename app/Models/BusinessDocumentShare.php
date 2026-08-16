<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\UniqueId;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A link to a document that works without an account.
 *
 * A different token from the document's public verification token and from a
 * signer's signing token — three tokens because they answer three different
 * questions: "is this genuine", "did you sign it", "may this be viewed".
 */
class BusinessDocumentShare extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'allow_download' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'business_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(BusinessDocumentShareAccess::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPasswordProtected(): bool
    {
        return $this->password_hash !== null;
    }

    /** Live: not revoked, not expired. A password-protected link is still live — it simply needs the password too. */
    public function isLive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function checkPassword(string $password): bool
    {
        return $this->password_hash !== null && Hash::check($password, $this->password_hash);
    }

    /** 32 chars of base62 — a link, not a password, but not guessable either. */
    public static function newShareToken(): string
    {
        return UniqueId::make(
            fn () => Str::random(32),
            fn (string $token) => static::query()->withoutGlobalScopes()->where('share_token', $token)->exists(),
        );
    }
}
