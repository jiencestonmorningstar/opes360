<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class VerificationToken extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(null, 'subject_type', 'subject_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(VerificationScan::class);
    }

    /**
     * 22 chars of base62, ~128 bits of entropy. Not derived from the record id:
     * nobody should be able to enumerate a business's invoices by incrementing
     * a URL. See docs/architecture/qr-ar.md.
     */
    public static function newToken(): string
    {
        return Str::random(22);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function publicUrl(): string
    {
        return url("/v/{$this->token}");
    }
}
