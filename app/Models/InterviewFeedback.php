<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One interviewer's scorecard: a 1-to-5 rating and their own words.
 *
 * Created blank when the panel is chosen; `submitted_at` marks the moment an
 * opinion was actually given, so a blank card reads as "not yet" rather than
 * as a zero dragging the average down.
 */
class InterviewFeedback extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $table = 'interview_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }
}
