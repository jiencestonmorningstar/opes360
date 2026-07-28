<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function verificationToken(): BelongsTo
    {
        return $this->belongsTo(VerificationToken::class);
    }

    /**
     * Canonical representation hashed at issue. Shared by PaymentRecorder (which
     * writes the hash) and the public verifier (which recomputes it), so the two
     * can never drift apart.
     */
    public function canonicalPayload(): string
    {
        return json_encode([
            'number' => $this->number,
            'payment_id' => $this->payment_id,
            'total' => (string) $this->total,
            'currency' => $this->currency,
            'issued_at' => $this->issued_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
    }

    public function isTampered(): bool
    {
        if (blank($this->content_hash)) {
            return false;
        }

        return ! hash_equals($this->content_hash, hash('sha256', $this->canonicalPayload()));
    }

    /** Print widths in millimetres, used to pick the CSS page size. */
    public function pageWidthMm(): int
    {
        return match ($this->format) {
            'thermal58' => 58,
            'thermal80' => 80,
            'a5' => 148,
            default => 210,
        };
    }
}
