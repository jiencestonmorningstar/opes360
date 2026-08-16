<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One candidate's run at one vacancy — the card the pipeline board moves.
 *
 * `stage` is where it is now; ApplicationStageMove is how it got there. All
 * movement goes through RecruitmentPipeline so the two can never disagree.
 */
class JobApplication extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /** In pipeline order. `rejected` is reachable from any of the others. */
    public const STAGES = [
        'applied' => 'Applied',
        'screening' => 'Screening',
        'interview' => 'Interview',
        'offer' => 'Offer',
        'hired' => 'Hired',
        'rejected' => 'Rejected',
    ];

    protected $guarded = ['id'];

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function stageMoves(): HasMany
    {
        return $this->hasMany(ApplicationStageMove::class)->orderBy('created_at');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class)->orderBy('scheduled_at');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(JobOffer::class)->latest();
    }

    /** The offer currently on the table, if any. */
    public function currentOffer(): ?JobOffer
    {
        return $this->offers()->whereNotIn('status', ['withdrawn'])->first();
    }

    public function isRejected(): bool
    {
        return $this->stage === 'rejected';
    }

    public function isHired(): bool
    {
        return $this->stage === 'hired';
    }

    /** Still moving through the pipeline — neither hired nor rejected. */
    public function isActive(): bool
    {
        return ! $this->isHired() && ! $this->isRejected();
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? ucfirst((string) $this->stage);
    }

    public function hasCv(): bool
    {
        return $this->cv_path !== null;
    }
}
