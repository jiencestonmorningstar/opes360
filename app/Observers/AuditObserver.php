<?php

namespace App\Observers;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the audit trail.
 *
 * Registered against the models whose history matters for money and identity.
 * Attribute-level diffs are recorded so "who changed this price, and from what"
 * is answerable, which a bare "updated" row cannot do.
 *
 * The writing itself lives in App\Support\Audit, because Eloquent events are
 * only half the trail: reads of sensitive records, exports and permission
 * changes have no model event to hang off, and they must land in the same table
 * with the same redaction rules or the log stops being one story.
 */
class AuditObserver
{
    /** @deprecated Kept as the historical name; the list lives in Audit. */
    protected const REDACTED = Audit::REDACTED;

    protected const IGNORED = Audit::IGNORED;

    public function created(Model $model): void
    {
        $this->record($model, 'created', ['after' => Audit::sanitise($model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = collect($model->getChanges())
            ->except(self::IGNORED)
            ->keys()
            ->all();

        if ($changes === []) {
            return;
        }

        $this->record($model, 'updated', [
            'before' => Audit::sanitise(array_intersect_key($model->getOriginal(), array_flip($changes))),
            'after' => Audit::sanitise(array_intersect_key($model->getAttributes(), array_flip($changes))),
        ]);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, method_exists($model, 'trashed') && $model->trashed() ? 'trashed' : 'deleted');
    }

    /**
     * Restoring a soft-deleted record is a decision somebody took, and without
     * this the trail shows a deletion that apparently never ended.
     *
     * @param  array<string, mixed>  $properties
     */
    public function restored(Model $model): void
    {
        $this->record($model, 'restored');
    }

    /** @param array<string, mixed> $properties */
    protected function record(Model $model, string $event, array $properties = []): void
    {
        Audit::record($model, $event, $properties);
    }
}
