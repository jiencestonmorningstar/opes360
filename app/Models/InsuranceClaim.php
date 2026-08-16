<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * One claim, from first notification to a decision.
 *
 * The evidence — photos, the assessor's report, the repudiation letter — is
 * managed business documents linked through the shared relations table, so it
 * inherits versioning, sharing and retention rather than growing a poorer
 * copy of each. The settlement decision is the workflow engine's: this model
 * is Approvable, the Claims service submits it, and a generic
 * `workflow.approved` listener settles it. There is no `approve()` anywhere
 * in the vertical, and a test asserts that stays true.
 */
class InsuranceClaim extends Model
{
    use Approvable;
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'fnol' => 'Notified',
        'assessed' => 'Assessed',
        'settled' => 'Settled',
        'rejected' => 'Rejected',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'incident_on' => 'date',
            'reported_on' => 'date',
            'settled_on' => 'date',
            'claimed_amount' => 'decimal:2',
            'settled_amount' => 'decimal:2',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The evidence, through the shared relations table. */
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

    public function isOpen(): bool
    {
        return in_array($this->status, ['fnol', 'assessed'], true);
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'settled' => ['label' => 'Settled', 'tone' => 'positive'],
            'rejected' => ['label' => 'Rejected', 'tone' => 'negative'],
            'assessed' => ['label' => 'Assessed', 'tone' => 'neutral'],
            default => ['label' => 'Notified', 'tone' => 'warning'],
        };
    }
}
