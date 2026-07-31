<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /** Business-card designs the stationery module can print. */
    public const CARD_DESIGNS = ['classic', 'bold', 'minimal', 'split'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'phones' => 'array',
            'socials' => 'array',
            'operating_hours' => 'array',
            'brand_tokens' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            // Encrypted at rest; tax_id_index carries lookups.
            'tax_id' => 'encrypted',
            'vat_number' => 'encrypted',
            'demo_expires_at' => 'datetime',
        ];
    }

    public function isDemo(): bool
    {
        return $this->account_type === 'demo';
    }

    public function isTrial(): bool
    {
        return $this->account_type === 'trial';
    }

    public function demoDaysLeft(): ?int
    {
        if (! $this->isDemo() || $this->demo_expires_at === null) {
            return null;
        }

        return max(0, (int) now()->diffInDays($this->demo_expires_at, false));
    }

    /** Ends the demo clock immediately and moves to a free trial. */
    public function endDemo(): void
    {
        $this->forceFill(['account_type' => 'trial', 'demo_expires_at' => null])->save();
    }

    /** Points earned for a given spend, rounded down — no fractional points. */
    public function loyaltyPointsFor(float $amountSpent): int
    {
        if ((float) $this->loyalty_points_per_amount <= 0) {
            return 0;
        }

        return (int) floor($amountSpent / (float) $this->loyalty_points_per_amount);
    }

    public function loyaltyPointValue(): float
    {
        return (float) $this->loyalty_point_value;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role_id', 'job_title', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Brand token with a fallback, e.g. brandToken('primary', '#2563eb').
     * Phase 2 populates these; until then templates get the defaults.
     */
    public function brandToken(string $key, mixed $default = null): mixed
    {
        return data_get($this->brand_tokens, $key, $default);
    }

    /**
     * The chosen card design, guarded so an unset or unknown value prints the
     * classic card rather than an empty template.
     */
    public function cardDesign(): string
    {
        return in_array($this->card_design, self::CARD_DESIGNS, true) ? $this->card_design : 'classic';
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }
}
