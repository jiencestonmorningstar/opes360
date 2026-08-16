<?php

namespace App\Models;

use App\Support\BrandPalette;
use App\Support\CardCatalog;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * The universal business-card designs the stationery module can print.
     *
     * The first four are the original set; the six that follow reproduce the
     * premium template sheet (docs/image templates/cards1.png, designs 01-06)
     * — each with its own front and back. The industry designs live in
     * App\Support\CardCatalog; cardDesigns() merges both.
     */
    public const CARD_DESIGNS = [
        'classic', 'bold', 'minimal', 'split',
        'azure', 'onyx', 'jade', 'cyber', 'violet', 'sunrise',
    ];

    /** Every printable card design: the universal set plus the industry catalogue. */
    public static function cardDesigns(): array
    {
        return array_merge(self::CARD_DESIGNS, CardCatalog::keys());
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'phones' => 'array',
            'socials' => 'array',
            'operating_hours' => 'array',
            'brand_tokens' => 'array',
            'branding' => 'array',
            'dunning' => 'array',
            'payroll_settings' => 'array',
            'modules' => 'array',
            'prints_confidential_footer' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            // Encrypted at rest; tax_id_index carries lookups.
            'tax_id' => 'encrypted',
            'vat_number' => 'encrypted',
            'demo_expires_at' => 'datetime',
            'plan_renews_at' => 'datetime',
            'referred_at' => 'datetime',
            'renewal_reminder_for' => 'date',
            'vat_registered' => 'boolean',
            'prices_include_tax' => 'boolean',
            'vat_rate' => 'decimal:4',
            'capital_social' => 'decimal:2',
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

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * A secretariat or print shop on the partner programme.
     *
     * Separate from account_type on purpose: that column carries the lifecycle
     * — demo, trial, active — and PlanEntitlements reads it, so a secretariat
     * is also one of those at any moment. See the programme migration.
     */
    public function isSecretariat(): bool
    {
        return $this->kind === 'secretariat';
    }

    /**
     * The code a partner hands out, minted on demand rather than at
     * registration so an account converted to a secretariat later still gets
     * one. Shaped to be read down a phone line: no vowels, so it cannot spell
     * anything, and no characters that are ambiguous in handwriting.
     */
    public function partnerCode(): string
    {
        if ($this->partner_code !== null) {
            return $this->partner_code;
        }

        do {
            $code = 'OPS-'.collect(str_split('123456789BCDFGHJKLMNPQRSTVWXYZ'))
                ->shuffle()->take(5)->implode('');
        } while (self::query()->withTrashed()->where('partner_code', $code)->exists());

        $this->forceFill(['partner_code' => $code])->save();

        return $code;
    }

    public function partnerClients(): HasMany
    {
        return $this->hasMany(PartnerClient::class);
    }

    public function cardIssuances(): HasMany
    {
        return $this->hasMany(CardIssuance::class);
    }

    public function partnerCommissions(): HasMany
    {
        return $this->hasMany(PartnerCommission::class);
    }

    public function partnerPayouts(): HasMany
    {
        return $this->hasMany(PartnerPayout::class);
    }

    /** The secretariat that enrolled this business, if one did. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_company_id');
    }

    /** Businesses this secretariat enrolled. */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_company_id');
    }

    public function platformAdminActivity(): MorphMany
    {
        return $this->morphMany(PlatformAdminActivity::class, 'subject');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CompanyNote::class);
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

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
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
     *
     * Resolution order, and the order matters:
     *
     *  1. An explicit `brand_tokens` entry. A business that pinned a specific
     *     colour for its printed stationery keeps it, whatever it later does
     *     on the branding screen.
     *  2. The derived palette. This is what makes the loyalty and VIP cards,
     *     and every other surface reading a brand token, pick up a company's
     *     branding without any of those views being edited.
     *  3. The caller's default.
     */
    public function brandToken(string $key, mixed $default = null): mixed
    {
        $explicit = data_get($this->brand_tokens, $key);

        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        // Guarded rather than a null coalesce into data_get: data_get($array,
        // null) hands back the whole array, so an unrecognised token would
        // return the entire palette instead of the caller's fallback.
        if (! array_key_exists($key, self::PALETTE_ALIASES)) {
            return $default;
        }

        return $this->palette()['light'][self::PALETTE_ALIASES[$key]] ?? $default;
    }

    /**
     * Names the printed templates ask for, mapped onto palette tokens. Kept
     * small on purpose — print asks for a handful of colours, not the whole
     * design system.
     */
    protected const PALETTE_ALIASES = [
        'primary' => '--color-fill-brand',
        'secondary' => '--color-fill-secondary',
        'ink' => '--color-ink',
        'muted' => '--color-muted',
        'accent' => '--color-fill-secondary',
    ];

    /** The derived token map for this company. Cached by BrandPalette. */
    public function palette(): array
    {
        return BrandPalette::for($this);
    }

    /** The owner's branding inputs, with platform defaults merged underneath. */
    public function brandingInputs(): array
    {
        return BrandPalette::inputsFor($this);
    }

    /**
     * The chosen card design, guarded so an unset or unknown value prints the
     * classic card rather than an empty template.
     */
    public function cardDesign(): string
    {
        return in_array($this->card_design, self::cardDesigns(), true) ? $this->card_design : 'classic';
    }

    /**
     * The logo, as a URL a printed page can load.
     *
     * One place rather than the same three lines in every print view: the
     * letterhead appears on invoices, receipts, statements and payslips, and a
     * business that changes its logo expects all four to change together.
     *
     * Nothing is cached and nothing is copied onto the document. The letterhead
     * is read from the company each time a page is rendered, so editing it
     * updates every document ever issued — which is what a business means by
     * "we changed our letterhead". The document's *content* is a separate
     * question and stays frozen: numbers, dates, lines and totals are covered
     * by the content hash its QR verifies against, and the logo is not.
     */
    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }
}
