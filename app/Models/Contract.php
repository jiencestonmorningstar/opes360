<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
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
 * An agreement the business is bound by.
 *
 * The commercial terms live here; the signed paper does not. A contract's
 * document is an ordinary BusinessDocument linked through the relations table,
 * so it inherits numbering, versions, signature, sharing and retention rather
 * than growing a second, poorer copy of each.
 *
 * The reason this model exists at all is `notice_by`. A PDF in a folder cannot
 * tell anyone that a cleaning contract renews for another year in eleven days
 * unless somebody serves notice; a dated, indexed row can.
 */
class Contract extends Model
{
    use Approvable;
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const TYPES = [
        'service' => 'Service',
        'supply' => 'Supply',
        'lease' => 'Lease',
        'licence' => 'Licence',
        'maintenance' => 'Maintenance',
        'employment' => 'Employment',
        'nda' => 'Non-disclosure',
        'framework' => 'Framework',
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
            'starts_on' => 'date',
            'ends_on' => 'date',
            'notice_by' => 'date',
            'terminated_on' => 'date',
            'value' => 'decimal:2',
            'renewal_term_months' => 'integer',
            'notice_period_days' => 'integer',
        ];
    }

    /**
     * What an automation rule may set. Filing and ownership only — never the
     * dates, the value or the status, because those are what the business
     * agreed and what the register reports, and a settings screen must not be
     * able to move a notice deadline.
     *
     * @return array<int, string>
     */
    public function automatableFields(): array
    {
        return ['description', 'notes', 'owner_id', 'type'];
    }

    /**
     * Deliberately NOT `contact()`: there is a `contact_id` column, and while
     * that particular name would survive, the register reads better for it and
     * the precedent is already set by `FixedAsset::locationRecord()` and
     * `Employee::departmentRecord()` — an attribute of the same name as a
     * relation shadows it silently and forever.
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(ContractObligation::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(ContractRenewal::class)->orderByDesc('renewed_on');
    }

    /**
     * The paperwork, through the shared relations table.
     *
     * `papers` rather than `documents` because `Document` in this product is a
     * sales document — an invoice or a quotation — and a relation called
     * `documents` on a contract would be read as those by anyone who has met
     * the rest of the codebase.
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

    /**
     * The contract's reference, taken from its paper.
     *
     * No number of its own, on purpose. Documents already numbers what it
     * issues, and a `CONTRACT-2026-000012` minted here would sit alongside the
     * number printed on the thing both parties signed and disagree with it.
     */
    public function reference(): ?string
    {
        return $this->papers()->first()?->reference;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isTerminated(): bool
    {
        return $this->status === 'terminated';
    }

    /** Still binding: running, and not called off. */
    public function isLive(): bool
    {
        return $this->isActive();
    }

    public function autoRenews(): bool
    {
        return $this->renewal_type === 'auto';
    }

    /**
     * The last day notice can still be given, worked out from the terms.
     *
     * Kept as the single definition — the model writes the stored column from
     * this on every save, so a screen, the watchlist and the database can
     * never be reading three different answers.
     */
    public function noticeDeadline(): ?Carbon
    {
        if ($this->ends_on === null || $this->notice_period_days === null) {
            return null;
        }

        return $this->ends_on->copy()->subDays($this->notice_period_days);
    }

    /** How many days are left to decide, negative once the moment has passed. */
    public function daysToNotice(?Carbon $asOf = null): ?int
    {
        if ($this->notice_by === null) {
            return null;
        }

        $asOf = ($asOf ?? Carbon::now())->copy()->startOfDay();

        return (int) $asOf->diffInDays($this->notice_by->copy()->startOfDay(), false);
    }

    /**
     * The expensive case: still running, renews itself, and the last day to
     * stop that has gone. Nobody has to do anything for this to cost money,
     * which is exactly why it needs saying out loud.
     */
    public function noticeMissed(?Carbon $asOf = null): bool
    {
        $days = $this->daysToNotice($asOf);

        return $this->isLive() && $days !== null && $days < 0;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    public function renewalLabel(): string
    {
        return self::RENEWAL_TYPES[$this->renewal_type] ?? 'Unknown';
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'active' => ['label' => 'Active', 'tone' => 'positive'],
            'expired' => ['label' => 'Expired', 'tone' => 'muted'],
            'terminated' => ['label' => 'Terminated', 'tone' => 'negative'],
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
         * The deadline is recomputed from the terms on every save rather than
         * being anybody's job to remember. Somebody extending a contract by
         * six months and leaving a notice date pointing at the old end is the
         * single most likely way this feature fails quietly, and this is the
         * only place that can be prevented for every writer at once.
         */
        static::saving(function (Contract $contract) {
            $contract->notice_by = $contract->noticeDeadline()?->toDateString();
        });
    }
}
