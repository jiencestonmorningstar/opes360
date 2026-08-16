<?php

namespace App\Livewire\Audit;

use App\Models\ActivityLog;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * "What has happened to this one record?", droppable onto any record's page.
 *
 * Takes a class name and a key rather than a model so it can be mounted from a
 * Blade view without the parent having to load anything, and so it still works
 * for a record that has since been deleted.
 *
 * Deliberately NOT gated on `audit.view`. That ability is sight of the whole
 * business's history, which is a much bigger thing than the history of a record
 * you are already looking at — requiring it would mean nobody but an auditor
 * ever sees a document's own timeline, and the panel would never be used. The
 * gate that matters here is the one the host page already applied to reach the
 * record at all; what this component must never do is show history for a record
 * in another company, which is enforced below.
 */
class History extends Component
{
    public string $subjectType = '';

    public string $subjectId = '';

    public int $limit = 20;

    public bool $expanded = false;

    public function mount(string|Model $subjectType, ?string $subjectId = null, int $limit = 20): void
    {
        if ($subjectType instanceof Model) {
            $subjectId = (string) $subjectType->getKey();
            $subjectType = $subjectType::class;
        }

        $this->subjectType = $subjectType;
        $this->subjectId = (string) $subjectId;
        $this->limit = $limit;
    }

    public function showAll(): void
    {
        $this->expanded = true;
    }

    public function render(): View
    {
        return view('livewire.audit.history', [
            'entries' => $this->entries(),
        ]);
    }

    /** @return Collection<int, ActivityLog> */
    protected function entries(): Collection
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null || $this->subjectId === '') {
            return collect();
        }

        /*
         * The company filter is the whole security of this component. Its
         * parameters arrive from the browser like any other Livewire property,
         * so somebody who knows — or guesses — a ULID could otherwise ask for
         * another business's record and be handed its history, including the
         * before/after values. ActivityLog has no CompanyScope to catch that,
         * so it is caught here.
         */
        return ActivityLog::query()
            ->where('company_id', $company->id)
            ->where('subject_type', $this->subjectType)
            ->where('subject_id', $this->subjectId)
            ->with('user')
            ->latest('created_at')
            ->limit($this->expanded ? 200 : $this->limit)
            ->get();
    }
}
