<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cover a broker has placed: who is covered, by whom, for what, until when.
 *
 * The policyholder and the insurer are both ordinary contacts — the client
 * being invoiced for the premium and the insurer being invoiced for the
 * commission are the same rows every other module reads. The schedule and the
 * wording are managed business documents reached through the shared relations
 * table. This model's own reason to exist is `covers_to` and `notice_by`:
 * a broker's expensive failure is cover that lapses before renewal is agreed,
 * and only a dated, indexed row can warn anybody in time.
 */
class InsurancePolicy extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const PRODUCT_LINES = [
        'motor' => 'Motor',
        'property' => 'Property',
        'health' => 'Health',
        'life' => 'Life',
        'liability' => 'Liability',
        'marine' => 'Marine & transit',
        'travel' => 'Travel',
        'other' => 'Other',
    ];

    public const RENEWAL_TYPES = [
        'none' => 'Does not renew',
        'auto' => 'Renews automatically',
        'manual' => 'Renewed by agreement',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'covers_from' => 'date',
            'covers_to' => 'date',
            'notice_by' => 'date',
            'cancelled_on' => 'date',
            'premium' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'renewal_term_months' => 'integer',
            'notice_period_days' => 'integer',
        ];
    }

    /*
     * Named `holder()` / `insurer()`, never `contact()`: there are two contact
     * columns on this table, and the register reads better for names that say
     * which chair each one sits in. Same precedent as Contract::counterparty().
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'holder_contact_id');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'insurer_contact_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class)->orderByDesc('incident_on');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(PolicyCommission::class)->orderByDesc('earned_on');
    }

    /** The link rows to the sales invoices that collect the premium. */
    public function premiumInvoiceLinks(): HasMany
    {
        return $this->hasMany(InsurancePolicyInvoice::class);
    }

    /** @return Collection<int, Document> the premium invoices themselves. */
    public function premiumInvoices(): Collection
    {
        return $this->premiumInvoiceLinks()->with('document')->get()
            ->pluck('document')->filter()->values();
    }

    /**
     * The paperwork — schedule, wording, endorsements — through the shared
     * relations table. `papers` rather than `documents`, because `Document`
     * in this product is a sales document and the premium invoices above are
     * exactly that.
     */
    public function documentRelations(): MorphMany
    {
        return $this->morphMany(BusinessDocumentRelation::class, 'related', 'related_type', 'related_id');
    }

    /** @return Collection<int, BusinessDocument> */
    public function papers()
    {
        return $this->documentRelations()->with('document')->get()
            ->pluck('document')->filter()->values();
    }

    public function label(): string
    {
        $line = self::PRODUCT_LINES[$this->product_line] ?? ucfirst((string) $this->product_line);

        return $line.' — '.($this->holder?->displayName() ?? 'No policyholder');
    }

    public function productLineLabel(): string
    {
        return self::PRODUCT_LINES[$this->product_line] ?? ucfirst((string) $this->product_line);
    }

    public function renewalLabel(): string
    {
        return self::RENEWAL_TYPES[$this->renewal_type] ?? 'Unknown';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function autoRenews(): bool
    {
        return $this->renewal_type === 'auto';
    }

    /**
     * The last day to act before renewal decides itself, from the terms.
     * The single definition — the saving hook writes the stored column from
     * this, so a screen, the watch and the database always agree.
     */
    public function noticeDeadline(): ?Carbon
    {
        if ($this->covers_to === null || $this->notice_period_days === null) {
            return null;
        }

        return $this->covers_to->copy()->subDays($this->notice_period_days);
    }

    /** Days of cover left, negative once it has run out. */
    public function daysOfCover(?Carbon $asOf = null): ?int
    {
        if ($this->covers_to === null) {
            return null;
        }

        $asOf = ($asOf ?? Carbon::now())->copy()->startOfDay();

        return (int) $asOf->diffInDays($this->covers_to->copy()->startOfDay(), false);
    }

    /** Cover has run out while the register still calls this active. */
    public function coverLapsed(?Carbon $asOf = null): bool
    {
        $days = $this->daysOfCover($asOf);

        return $this->isActive() && $days !== null && $days < 0;
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'active' => ['label' => 'In force', 'tone' => 'positive'],
            'expired' => ['label' => 'Expired', 'tone' => 'muted'],
            'cancelled' => ['label' => 'Cancelled', 'tone' => 'negative'],
            default => ['label' => 'Draft', 'tone' => 'neutral'],
        };
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected static function booted(): void
    {
        /*
         * Recomputed on every save rather than being anybody's job — the same
         * bargain, for the same reason, as Contract::booted(). Somebody
         * extending cover by six months and leaving the notice date pointing
         * at the old expiry is the likeliest way this fails quietly.
         */
        static::saving(function (InsurancePolicy $policy) {
            $policy->notice_by = $policy->noticeDeadline()?->toDateString();
        });
    }
}
