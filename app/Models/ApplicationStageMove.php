<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an application's history: who moved it, from where, to where.
 *
 * Append-only — there is no update path anywhere in the module, because a
 * history that can be edited is not a history. Written only by
 * RecruitmentPipeline, in the same transaction as the stage change itself.
 */
class ApplicationStageMove extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
