<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named sequence of steps, attached to a model class.
 *
 * One engine for the whole product. Documents, Procurement, Expenses and HR
 * ask for an approval rather than each growing their own — four approval
 * engines is the failure mode the brief names outright.
 *
 * A platform service, not a module: it is deliberately absent from
 * config/modules.php, because a business that switches Procurement off must
 * keep the approval history of the purchase orders it already raised.
 */
class Workflow extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    /**
     * The workflow this is a frozen copy of, if it is one.
     *
     * Set when somebody edited a workflow that had approvals running against
     * it: the old rules were copied aside and the running approvals moved onto
     * the copy, so they finish under the rules they started under. See
     * App\Services\Workflow\WorkflowVersioning.
     */
    public function archivedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'archived_from_id');
    }

    public function isArchivedVersion(): bool
    {
        return $this->archived_from_id !== null;
    }

    /**
     * The workflows a business actually wrote.
     *
     * Frozen copies are excluded everywhere a workflow is listed or offered.
     * They are not approval paths anybody may choose; they are a record of the
     * rules some half-finished approval is still being judged by, and offering
     * one would let a business pick rules it had already replaced.
     */
    public function scopeDefinitions(Builder $query): Builder
    {
        return $query->whereNull('archived_from_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForSubject(Builder $query, string $subjectType): Builder
    {
        return $query->where('subject_type', $subjectType);
    }

    /** The one a module gets when it asks for "the" workflow for a record. */
    public static function defaultFor(string $subjectType): ?self
    {
        return self::query()
            ->definitions()
            ->active()
            ->forSubject($subjectType)
            ->where('is_default', true)
            ->first();
    }

    protected static function booted(): void
    {
        /*
         * One default per subject type.
         *
         * Enforced here rather than by a unique index, because "at most one
         * row where is_default is true" is not a uniqueness constraint the
         * databases involved can express portably — a partial index in
         * Postgres, a generated column trick in MySQL, and nothing at all in
         * SQLite.
         */
        static::saved(function (self $workflow) {
            if (! $workflow->is_default) {
                return;
            }

            self::query()
                ->where('subject_type', $workflow->subject_type)
                ->whereKeyNot($workflow->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }
}
