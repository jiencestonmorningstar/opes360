<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sale you are in the middle of making.
 *
 * Deliberately thin — a contact, an amount, a stage and a date. The parts of a
 * CRM that predict things need a volume of history a small business does not
 * have, and they are the first parts to start lying.
 */
class Deal extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * The board, left to right. Order matters: the UI renders columns from
     * this list, and `isOpen()` reads the two closed states off the end.
     */
    public const STAGES = [
        'lead' => 'Lead',
        'qualified' => 'Qualified',
        'proposal' => 'Proposal',
        'won' => 'Won',
        'lost' => 'Lost',
    ];

    public const CLOSED_STAGES = ['won', 'lost'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'expected_close_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->stage, self::CLOSED_STAGES, true);
    }

    /** The name to show, whether or not this is a contact yet. */
    public function displayName(): string
    {
        return $this->contact?->name ?? $this->lead_name ?? 'Unnamed lead';
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', self::CLOSED_STAGES);
    }

    public function scopeStage(Builder $query, string $stage): Builder
    {
        return $query->where('stage', $stage);
    }
}
