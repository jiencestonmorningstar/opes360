<?php

namespace App\Models\Concerns;

use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Marks a model as replicated to devices, and stamps its position in the
 * company's change stream on every write.
 *
 * The stamp has to happen here rather than only in the sync engine. Devices pull
 * "everything after cursor N", so a record saved through a web form without a
 * sequence is invisible to that query forever — the phone would only ever see
 * records other phones had created, which is precisely backwards.
 */
trait Syncable
{
    public static function bootSyncable(): void
    {
        static::saving(function ($model) {
            // Only when something actually changed: re-saving an untouched model
            // should not push it to the front of every device's next pull.
            if ($model->exists && ! $model->isDirty()) {
                return;
            }

            $model->sync_sequence = static::nextSyncSequence();
        });
    }

    /**
     * Monotonic per-company cursor. Wall-clock time is unusable here: devices
     * and servers disagree about it, and a clock that steps backwards would make
     * records skip a pull entirely.
     */
    public static function nextSyncSequence(): int
    {
        $companyId = app(CurrentCompany::class)->id();

        DB::table('sync_sequences')->updateOrInsert(['company_id' => $companyId], []);
        DB::table('sync_sequences')->where('company_id', $companyId)->increment('value');

        return (int) DB::table('sync_sequences')->where('company_id', $companyId)->value('value');
    }
}
