<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing being done about a risk, with its own owner and its own state.
 *
 * Several rows rather than one "mitigation" paragraph, because the paragraph
 * is what a business already has in a spreadsheet and it is precisely why
 * nobody can answer which mitigations exist, who owns them, or whether any of
 * them was ever put in place.
 */
class RiskControl extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const KINDS = [
        'preventive' => 'Preventive',
        'detective' => 'Detective',
        'corrective' => 'Corrective',
    ];

    public const STATUSES = [
        'planned' => 'Planned',
        'in_place' => 'In place',
        'failed' => 'Not working',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'implemented_on' => 'date',
            'effectiveness' => 'integer',
        ];
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isInPlace(): bool
    {
        return $this->status === 'in_place';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /** Planned, overdue and nobody has said anything. The quiet failure. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'planned')
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', now()->toDateString());
    }
}
