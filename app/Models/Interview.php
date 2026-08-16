<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A scheduled conversation with a candidate.
 *
 * The panel IS the feedback rows: scheduling creates one blank scorecard per
 * interviewer, so "who was asked" and "who scored" are one list that cannot
 * drift apart.
 */
class Interview extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(InterviewFeedback::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The panel average, from the scorecards actually submitted. */
    public function averageRating(): ?float
    {
        $avg = $this->feedback()->whereNotNull('rating')->avg('rating');

        return $avg === null ? null : round((float) $avg, 1);
    }
}
