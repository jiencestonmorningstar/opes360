<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One thing done or planned against a deal or a lead.
 *
 * The planned side of CRM. The audit trail already records what CHANGED; this
 * records what somebody intends — the call to make Thursday, the meeting that
 * happened but moved no field. Two timestamps instead of a status column:
 * "due and not done" is overdue, with nothing to fall out of step.
 */
class CrmActivity extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const KINDS = [
        'call' => 'Call',
        'meeting' => 'Meeting',
        'note' => 'Note',
        'task' => 'Task',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    /**
     * The one way an activity is written, so the "a note is instantly done"
     * rule is decided in exactly one place. Anything with no due date happened
     * as it was typed; anything with one is a plan until complete() says so.
     */
    public static function log(
        Model $subject,
        string $kind,
        string $summary,
        ?User $actor = null,
        ?string $details = null,
        ?Carbon $dueAt = null,
    ): self {
        return static::create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'kind' => $kind,
            'summary' => $summary,
            'details' => $details,
            'due_at' => $dueAt,
            'done_at' => $dueAt === null ? now() : null,
            'user_id' => $actor?->id,
        ]);
    }

    public function complete(): void
    {
        if ($this->done_at === null) {
            $this->forceFill(['done_at' => now()])->save();
        }
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOverdue(): bool
    {
        return $this->done_at === null && $this->due_at !== null && $this->due_at->isPast();
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function scopePlanned(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNull('done_at')->where('due_at', '<', now());
    }
}
