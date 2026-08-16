<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something one side of a contract promised to do.
 *
 * Both sides live in one table. "What do we still owe them" and "what have
 * they failed to deliver" are the same question asked from opposite chairs,
 * and splitting them into two tables would mean writing every report twice.
 */
class ContractObligation extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const SIDES = [
        'us' => 'We must',
        'them' => 'They must',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_on' => 'date',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isDone(): bool
    {
        return $this->completed_on !== null;
    }

    public function sideLabel(): string
    {
        return self::SIDES[$this->owed_by] ?? 'Someone must';
    }

    /**
     * Late, and still outstanding.
     *
     * An obligation with no date is never late — some promises are standing
     * ones ("keep the site tidy") and treating those as due today would bury
     * the ones somebody can actually tick off.
     */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        return ! $this->isDone()
            && $this->due_on !== null
            && $this->due_on->lt(($asOf ?? Carbon::now())->copy()->startOfDay());
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('completed_on');
    }

    /**
     * Outstanding, dated, and the date has gone — restricted to contracts that
     * are still running, because chasing a supplier for a report under an
     * agreement the business terminated is how a task list loses its
     * credibility.
     */
    public function scopeOverdue(Builder $query, ?Carbon $asOf = null): Builder
    {
        return $query
            ->outstanding()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', ($asOf ?? Carbon::now())->toDateString())
            ->whereHas('contract', fn (Builder $q) => $q->live());
    }
}
