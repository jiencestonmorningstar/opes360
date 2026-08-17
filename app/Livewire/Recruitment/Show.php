<?php

namespace App\Livewire\Recruitment;

use App\Models\Interview;
use App\Models\JobApplication;
use App\Services\Recruitment\JobOffers;
use App\Services\Recruitment\RecruitmentPipeline;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One application: the person, their timeline, their interviews, their offer.
 *
 * Every state change delegates to the services, which are where the rules
 * live — this component decides only who may press which button. Interview
 * feedback is the one write open to `recruitment.interview` holders without
 * `recruitment.manage`: a panel member must be able to score their own
 * interview without being able to move anybody's card.
 */
class Show extends Component
{
    public JobApplication $application;

    // Reject
    public string $rejectionReason = '';

    // Interview scheduling
    public string $interviewAt = '';

    public string $interviewLocation = '';

    /** @var array<int, int> */
    public array $interviewerIds = [];

    // Scorecard
    public ?string $feedbackInterviewId = null;

    public ?int $feedbackRating = null;

    public string $feedbackNotes = '';

    // Offer
    public string $offerAmount = '';

    public string $offerStartsOn = '';

    public function mount(JobApplication $application): void
    {
        Gate::authorize('recruitment.view');

        $this->application = $application;
    }

    /**
     * The CV, streamed from the private documents disk.
     *
     * A CV never gets a public URL — the disk is private precisely because an
     * application is somebody's personal papers. Anyone who may read the
     * application may read the CV, so the gate is `recruitment.view`, checked
     * here as well as in mount() because Livewire actions arrive on their own
     * requests. The file goes out under the candidate's name (keeping the
     * upload's extension) so a folder of downloads reads as people, not
     * as `document(7).pdf`.
     */
    public function downloadCv(): StreamedResponse
    {
        Gate::authorize('recruitment.view');

        abort_unless($this->application->hasCv(), 404);

        $extension = pathinfo((string) $this->application->cv_name, PATHINFO_EXTENSION)
            ?: pathinfo((string) $this->application->cv_path, PATHINFO_EXTENSION);

        $name = $this->application->candidate->name().($extension !== '' ? '.'.$extension : '');

        return Storage::disk($this->application->cv_disk ?? 'documents')
            ->download($this->application->cv_path, $name);
    }

    public function moveStage(string $to): void
    {
        Gate::authorize('recruitment.manage');

        $this->act(fn () => app(RecruitmentPipeline::class)
            ->moveStage($this->application, $to, auth()->user()));
    }

    public function reject(): void
    {
        Gate::authorize('recruitment.manage');

        $this->validate(
            ['rejectionReason' => ['required', 'string', 'max:255']],
            ['rejectionReason.required' => 'Give the reason — it is kept on the record.'],
        );

        $this->act(fn () => app(RecruitmentPipeline::class)
            ->reject($this->application, auth()->user(), $this->rejectionReason));

        $this->rejectionReason = '';
    }

    public function scheduleInterview(): void
    {
        Gate::authorize('recruitment.manage');

        $this->validate([
            'interviewAt' => ['required', 'date'],
            'interviewLocation' => ['nullable', 'string', 'max:255'],
            'interviewerIds' => ['required', 'array', 'min:1'],
        ], [
            'interviewerIds.required' => 'Pick at least one interviewer.',
        ]);

        $this->act(fn () => app(RecruitmentPipeline::class)->scheduleInterview(
            $this->application,
            $this->interviewAt,
            array_map('intval', $this->interviewerIds),
            auth()->user(),
            $this->interviewLocation ?: null,
        ));

        $this->reset(['interviewAt', 'interviewLocation', 'interviewerIds']);
    }

    public function startFeedback(string $interviewId): void
    {
        Gate::authorize('recruitment.interview');

        $interview = Interview::findOrFail($interviewId);
        $card = $interview->feedback()->where('interviewer_id', auth()->id())->first();

        $this->feedbackInterviewId = $interview->id;
        $this->feedbackRating = $card?->rating;
        $this->feedbackNotes = (string) ($card?->notes ?? '');
    }

    public function saveFeedback(): void
    {
        Gate::authorize('recruitment.interview');

        $this->validate([
            'feedbackRating' => ['required', 'integer', 'min:1', 'max:5'],
            'feedbackNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $interview = Interview::findOrFail($this->feedbackInterviewId);

        $this->act(fn () => app(RecruitmentPipeline::class)->recordFeedback(
            $interview,
            auth()->user(),
            $this->feedbackRating,
            $this->feedbackNotes ?: null,
        ));

        $this->reset(['feedbackInterviewId', 'feedbackRating', 'feedbackNotes']);
    }

    public function makeOffer(): void
    {
        Gate::authorize('recruitment.offer');

        $this->validate([
            'offerAmount' => ['required', 'numeric', 'min:1'],
            'offerStartsOn' => ['required', 'date'],
        ], [
            'offerAmount.required' => 'What is the monthly salary being offered?',
        ]);

        $this->act(fn () => app(JobOffers::class)->make(
            $this->application,
            (float) $this->offerAmount,
            $this->offerStartsOn,
            auth()->user(),
        ));

        $this->reset(['offerAmount', 'offerStartsOn']);
    }

    public function submitOffer(string $offerId): void
    {
        Gate::authorize('recruitment.offer');

        $offer = $this->application->offers()->findOrFail($offerId);

        $this->act(fn () => app(JobOffers::class)->submit($offer, auth()->user()));
    }

    public function acceptOffer(string $offerId): void
    {
        Gate::authorize('recruitment.offer');

        $offer = $this->application->offers()->findOrFail($offerId);

        $this->act(fn () => app(JobOffers::class)->accept($offer, auth()->user()));
    }

    public function declineOffer(string $offerId, string $reason = ''): void
    {
        Gate::authorize('recruitment.offer');

        $offer = $this->application->offers()->findOrFail($offerId);

        $this->act(fn () => app(JobOffers::class)->decline($offer, auth()->user(), $reason ?: null));
    }

    public function withdrawOffer(string $offerId): void
    {
        Gate::authorize('recruitment.offer');

        $offer = $this->application->offers()->findOrFail($offerId);

        $this->act(fn () => app(JobOffers::class)->withdraw($offer, auth()->user()));
    }

    /**
     * The services throw plain-language RuntimeExceptions; the screen shows
     * them beside the work instead of a 500 page.
     */
    protected function act(callable $action): void
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        $this->application = $this->application->fresh();
    }

    public function render(): View
    {
        $this->application->loadMissing([
            'company', 'candidate', 'vacancy.position', 'stageMoves.mover',
            'interviews.feedback.interviewer', 'offers.letter',
        ]);

        $team = $this->application->company->users()->get();

        return view('livewire.recruitment.show', [
            'team' => $team,
            'stages' => JobApplication::STAGES,
        ])->layout('components.layouts.app', [
            'title' => $this->application->candidate->name(),
            'active' => 'team',
        ]);
    }
}
