<?php

namespace App\Livewire\Audit;

use App\Models\ActivityLog;
use App\Support\Audit;
use App\Support\AuditSubjects;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The audit trail, readable.
 *
 * The rows have existed since the first release and nobody could look at them,
 * which made the whole thing worthless in the one situation it is kept for —
 * an argument about who changed something. Four filters, because those are the
 * four ways the question is ever actually asked: about a person, about a
 * record, about a day, or about a kind of action.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $actorId = '';

    #[Url]
    public string $event = '';

    #[Url]
    public string $subjectType = '';

    #[Url]
    public string $subjectId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('audit.view');
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['actorId', 'event', 'subjectType', 'subjectId', 'from', 'to', 'search']);
        $this->resetPage();
    }

    public function render(): View
    {
        Gate::authorize('audit.view');

        $company = app(CurrentCompany::class)->get();

        /*
         * ActivityLog deliberately has no CompanyScope — it records
         * system-level events with no company at all — so the tenant boundary
         * has to be drawn here, by hand, on every query. There is no global
         * scope to fall back on if this line is ever dropped, which is why it
         * is first and unconditional. A null company means no rows, not all
         * rows.
         */
        $entries = ActivityLog::query()
            ->where('company_id', $company?->id ?? '~none~')
            ->when($this->actorId !== '', fn ($q) => $q->where('user_id', $this->actorId))
            ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
            ->when($this->subjectType !== '', fn ($q) => $q->where('subject_type', $this->subjectType))
            ->when($this->subjectId !== '', fn ($q) => $q->where('subject_id', $this->subjectId))
            /*
             * Bounded on startOfDay/endOfDay rather than passed as bare dates.
             * A date string compares as midnight, so `<= '2026-08-10'` excludes
             * everything that happened on the 10th — which is precisely the day
             * somebody searching a range cares about, because it is usually the
             * day they noticed the problem.
             */
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->from)->startOfDay()))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->to)->endOfDay()))
            ->when($this->search !== '', fn ($q) => $q->where('subject_label', 'like', '%'.$this->search.'%'))
            ->with('user')
            ->latest('created_at')
            ->paginate(30);

        return view('livewire.audit.index', [
            'entries' => $entries,
            'actors' => $company ? $company->users()->wherePivot('status', 'active')->orderBy('users.name')->get() : collect(),
            'events' => AuditSubjects::events(),
            'subjectTypes' => AuditSubjects::typesPresentIn($company?->id),
            'readWindow' => Audit::READ_WINDOW_MINUTES,
        ])->layout('components.layouts.app', ['title' => 'Audit trail', 'active' => 'settings']);
    }
}
