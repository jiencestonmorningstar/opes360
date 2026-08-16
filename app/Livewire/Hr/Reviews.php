<?php

namespace App\Livewire\Hr;

use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\Position;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Appraisals: writing one, handing it over, and signing it.
 *
 * Two audiences on one screen, which is why it has tabs. A manager with
 * `reviews.manage` writes and shares. An employee with no HR permission at all
 * still belongs here, because acknowledging their own review is not something
 * an ability grants — see acknowledge().
 */
class Reviews extends Component
{
    #[Url]
    public string $tab = 'all'; // all|mine

    // ── Write form ──────────────────────────────────────────────────────
    public bool $writing = false;

    public ?string $editing = null;

    public string $employeeId = '';

    public ?string $positionId = null;

    public string $cycle = 'annual';

    public string $periodStartsOn = '';

    public string $periodEndsOn = '';

    public string $overallRating = '';

    public string $summary = '';

    public string $strengths = '';

    public string $improvements = '';

    public string $goals = '';

    // ── Acknowledgement ─────────────────────────────────────────────────
    public ?string $acknowledging = null;

    public ?string $commenting = null;

    public string $employeeComment = '';

    public function mount(): void
    {
        // Somebody with no reading permission is here for their own review and
        // nothing else, so that is the tab they land on.
        if (! Gate::allows('reviews.view')) {
            $this->tab = 'mine';
        }

        $this->periodStartsOn = now()->startOfYear()->toDateString();
        $this->periodEndsOn = now()->endOfYear()->toDateString();
    }

    public function startWriting(): void
    {
        Gate::authorize('reviews.manage');

        $this->reset(['editing', 'employeeId', 'positionId', 'overallRating',
            'summary', 'strengths', 'improvements', 'goals']);
        $this->resetValidation();

        $this->cycle = 'annual';
        $this->periodStartsOn = now()->startOfYear()->toDateString();
        $this->periodEndsOn = now()->endOfYear()->toDateString();
        $this->writing = true;
    }

    public function edit(string $id): void
    {
        Gate::authorize('reviews.manage');

        $review = PerformanceReview::findOrFail($id);

        $this->resetValidation();
        $this->editing = $review->id;
        $this->employeeId = $review->employee_id;
        $this->positionId = $review->position_id;
        $this->cycle = $review->cycle;
        $this->periodStartsOn = $review->period_starts_on->toDateString();
        $this->periodEndsOn = $review->period_ends_on->toDateString();
        $this->overallRating = $review->overall_rating === null ? '' : (string) $review->overall_rating;
        $this->summary = (string) $review->summary;
        $this->strengths = (string) $review->strengths;
        $this->improvements = (string) $review->improvements;
        $this->goals = (string) $review->goals;
        $this->writing = true;
    }

    public function cancel(): void
    {
        $this->writing = false;
        $this->acknowledging = null;
        $this->commenting = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('reviews.manage');

        $this->validate([
            'employeeId' => ['required', 'string'],
            'positionId' => ['nullable', 'string'],
            'cycle' => ['required', 'in:'.implode(',', array_keys(PerformanceReview::CYCLES))],
            'periodStartsOn' => ['required', 'date'],
            'periodEndsOn' => ['required', 'date', 'after_or_equal:periodStartsOn'],
            'overallRating' => ['nullable', 'in:1,2,3,4,5'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'strengths' => ['nullable', 'string', 'max:5000'],
            'improvements' => ['nullable', 'string', 'max:5000'],
            'goals' => ['nullable', 'string', 'max:5000'],
        ], [
            'employeeId.required' => 'Who is this review about?',
            'periodEndsOn.after_or_equal' => 'A review period cannot end before it starts.',
        ]);

        $attributes = [
            'employee_id' => $this->employeeId,
            'position_id' => $this->positionId ?: null,
            'cycle' => $this->cycle,
            'period_starts_on' => $this->periodStartsOn,
            'period_ends_on' => $this->periodEndsOn,
            'overall_rating' => $this->overallRating === '' ? null : (int) $this->overallRating,
            'summary' => $this->summary ?: null,
            'strengths' => $this->strengths ?: null,
            'improvements' => $this->improvements ?: null,
            'goals' => $this->goals ?: null,
        ];

        try {
            if ($this->editing !== null) {
                PerformanceReview::findOrFail($this->editing)->update($attributes);
            } else {
                PerformanceReview::create($attributes + [
                    'status' => 'draft',
                    'reviewer_id' => auth()->id(),
                    'created_by' => auth()->id(),
                ]);
            }
        } catch (RuntimeException $e) {
            // The model refuses a rewrite of an acknowledged verdict, and that
            // refusal is the point of the record — shown, not swallowed.
            $this->addError('summary', $e->getMessage());

            return;
        }

        $this->writing = false;
        $this->editing = null;
        $this->dispatch('toast', message: 'Review saved.');
    }

    /** Hand it to the employee. Until this, they cannot see it. */
    public function share(string $id): void
    {
        Gate::authorize('reviews.manage');

        try {
            PerformanceReview::findOrFail($id)->share();
        } catch (RuntimeException $e) {
            $this->addError('share', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Shared with the employee.');
    }

    public function startAcknowledging(string $id): void
    {
        $review = $this->ownReview($id);

        $this->acknowledging = $review->id;
        $this->commenting = null;
        $this->employeeComment = (string) $review->employee_comment;
    }

    public function startComment(string $id): void
    {
        $review = $this->ownReview($id);

        $this->commenting = $review->id;
        $this->acknowledging = null;
        $this->employeeComment = (string) $review->employee_comment;
    }

    /**
     * The employee signs their own review.
     *
     * Deliberately no ability check. The right to acknowledge comes from being
     * the subject of the document, exactly as an approver's right comes from
     * having been asked. A permission would let an administrator grant somebody
     * the power to sign off a review that is not about them, which is the one
     * signature this record exists to be able to produce later.
     */
    public function acknowledge(): void
    {
        $review = $this->ownReview((string) $this->acknowledging);

        try {
            $review->acknowledge($this->employeeComment ?: null);
        } catch (RuntimeException $e) {
            $this->addError('acknowledging', $e->getMessage());

            return;
        }

        $this->acknowledging = null;
        $this->dispatch('toast', message: 'Acknowledged.');
    }

    /**
     * The employee's own words, which stay theirs to change afterwards.
     *
     * What froze at acknowledgement was the employer's verdict. Freezing the
     * employee's reply as well would mean the only person who cannot add to
     * the file is the one it is about.
     */
    public function saveComment(string $id): void
    {
        $review = $this->ownReview($id);

        $review->update(['employee_comment' => $this->employeeComment ?: null]);

        $this->commenting = null;
        $this->dispatch('toast', message: 'Your comment has been saved.');
    }

    /**
     * The review, if the signed-in user is the person it is about.
     *
     * A staff member with no login has no user_id, so a null on either side
     * must never be read as a match — that would hand every unlinked
     * employee's review to whoever asked for it.
     */
    protected function ownReview(string $id): PerformanceReview
    {
        $review = PerformanceReview::with('employee')->findOrFail($id);

        abort_unless(
            $review->employee?->user_id !== null && $review->employee->user_id === auth()->id(),
            403,
            'Only the person a review is about can sign it.'
        );

        return $review;
    }

    public function render(): View
    {
        $mine = PerformanceReview::query()
            ->whereIn('employee_id', Employee::query()->where('user_id', auth()->id())->select('id'))
            // A draft is the reviewer's working copy, not yet an appraisal.
            ->where('status', '!=', 'draft')
            ->with(['reviewer', 'position', 'employee'])
            ->latestFirst()
            ->get();

        $all = Gate::allows('reviews.view')
            ? PerformanceReview::query()
                ->with(['employee', 'reviewer', 'position'])
                ->latestFirst()
                ->get()
            : collect();

        return view('livewire.hr.reviews', [
            'all' => $all,
            'mine' => $mine,
            'people' => Employee::query()
                ->where('status', 'active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
            'positions' => Position::query()->active()->orderBy('title')->get(),
            'cycles' => PerformanceReview::CYCLES,
            'ratings' => PerformanceReview::RATINGS,
            'statuses' => PerformanceReview::STATUSES,
        ])->layout('components.layouts.app', ['title' => 'Performance reviews', 'active' => 'team']);
    }
}
