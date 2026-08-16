<?php

namespace App\Livewire\Recruitment;

use App\Models\JobApplication;
use App\Models\Position;
use App\Models\Vacancy;
use App\Services\Recruitment\RecruitmentPipeline;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Vacancies, and the pipeline of everyone applying to them.
 *
 * One screen rather than two because a business this size hires one or two
 * people at a time: "what are we hiring for" and "who has applied" are the
 * same glance. The board moves cards through applied → screening → interview;
 * offer and hired happen on the application's own page, where the offer and
 * its approval live.
 */
class Index extends Component
{
    #[Url]
    public string $vacancyFilter = '';

    public bool $adding = false;

    public string $positionId = '';

    public string $description = '';

    public int $openings = 1;

    public function mount(): void
    {
        Gate::authorize('recruitment.view');
    }

    public function startAdding(): void
    {
        Gate::authorize('recruitment.manage');

        $this->reset(['positionId', 'description']);
        $this->openings = 1;
        $this->resetValidation();
        $this->adding = true;
    }

    public function cancel(): void
    {
        $this->adding = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('recruitment.manage');

        $this->validate([
            'positionId' => ['required', 'string', 'exists:positions,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'openings' => ['required', 'integer', 'min:1', 'max:100'],
        ], [
            'positionId.required' => 'A vacancy advertises a position. Pick one, or create the position first.',
        ]);

        Vacancy::create([
            'position_id' => $this->positionId,
            'description' => $this->description ?: null,
            'openings' => $this->openings,
            // Explicit: Model::create() does not read column defaults back.
            'status' => 'draft',
            'share_token' => Vacancy::newShareToken(),
            'created_by' => auth()->id(),
        ]);

        $this->adding = false;

        session()->flash('status', 'Vacancy created. Open it when you are ready to receive applications.');
    }

    public function open(string $id): void
    {
        Gate::authorize('recruitment.manage');

        Vacancy::findOrFail($id)->update(['status' => 'open']);
    }

    public function close(string $id): void
    {
        Gate::authorize('recruitment.manage');

        Vacancy::findOrFail($id)->update(['status' => 'closed']);
    }

    /** A drag on the board — applied/screening/interview only, by design. */
    public function moveStage(string $applicationId, string $to): void
    {
        Gate::authorize('recruitment.manage');

        $application = JobApplication::findOrFail($applicationId);

        app(RecruitmentPipeline::class)->moveStage($application, $to, auth()->user());
    }

    public function render(): View
    {
        $vacancies = Vacancy::query()
            ->with('position')
            ->withCount('applications')
            ->latest()
            ->get();

        $applications = JobApplication::query()
            ->with(['candidate', 'vacancy.position'])
            ->when($this->vacancyFilter !== '', fn ($q) => $q->where('vacancy_id', $this->vacancyFilter))
            ->latest()
            ->get()
            ->groupBy('stage');

        $positions = Position::query()->active()->orderBy('title')->get();

        return view('livewire.recruitment.index', [
            'vacancies' => $vacancies,
            'applications' => $applications,
            'positions' => $positions,
            'stages' => JobApplication::STAGES,
            'company' => app(CurrentCompany::class)->get(),
        ])->layout('components.layouts.app', ['title' => 'Recruitment', 'active' => 'team']);
    }
}
