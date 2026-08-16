<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One occurrence of an obligation: the Q2 return, the 2026 licence.
 *
 * Approvable, so sign-off runs through the platform workflow engine rather
 * than a second approval mechanism grown inside compliance. The engine
 * remains the authority on who must act; `status` here only records where the
 * filing has got to, and is set from the engine's verdict by a listener.
 */
class ComplianceFiling extends Model
{
    use Approvable;
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;

    public const STATUSES = [
        'draft' => 'Being prepared',
        'submitted' => 'Awaiting sign-off',
        'completed' => 'Filed',
        'rejected' => 'Refused',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Named `obligation`, and the column is `compliance_obligation_id`, so
     * nothing shadows anything. Eloquent resolves an attribute before a
     * same-named relation without a word of complaint, and a relation called
     * `compliance_obligation` would be exactly that trap.
     */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(ComplianceObligation::class, 'compliance_obligation_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDone(): bool
    {
        return $this->status === 'completed';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'submitted'], true);
    }

    /** Filed after the deadline. The question an inspector asks first. */
    public function wasLate(): bool
    {
        return $this->isDone()
            && $this->completed_on !== null
            && $this->completed_on->startOfDay()->isAfter($this->due_on->startOfDay());
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['draft', 'submitted']);
    }
}
