<?php

namespace App\Livewire\Risks;

use App\Models\Risk;
use App\Models\RiskControl;
use App\Services\Compliance\RiskRegister;
use App\Support\ComplianceCalendar;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * What could go wrong, what is being done about it, and when somebody last
 * looked.
 *
 * Ranked by the untreated score on purpose. Sorting by residual would put the
 * risks somebody has declared handled at the bottom, which is exactly where an
 * optimistic reassessment would like them to be.
 */
class Index extends Component
{
    #[Url]
    public string $tab = 'register'; // register|review

    /** Which risk's controls and forms are expanded. */
    public ?string $open = null;

    // ── New risk ────────────────────────────────────────────────────────
    public bool $adding = false;

    public string $title = '';

    public string $description = '';

    public string $category = 'operational';

    public string $likelihood = '3';

    public string $impact = '3';

    public string $treatment = 'mitigate';

    public ?string $riskOwnerId = null;

    public string $reviewIntervalMonths = '6';

    // ── New control ─────────────────────────────────────────────────────
    public ?string $addingControlTo = null;

    public string $controlTitle = '';

    public string $controlKind = 'preventive';

    public string $controlDueOn = '';

    public ?string $controlOwnerId = null;

    // ── Reassessment ────────────────────────────────────────────────────
    public ?string $reassessing = null;

    public string $residualLikelihood = '';

    public string $residualImpact = '';

    // ── Review ──────────────────────────────────────────────────────────
    public ?string $reviewing = null;

    public string $reviewedOn = '';

    public string $reviewNotes = '';

    // ── Closing ─────────────────────────────────────────────────────────
    public ?string $closing = null;

    public string $closureReason = '';

    public function mount(): void
    {
        Gate::authorize('risks.view');
    }

    public function startAdding(): void
    {
        Gate::authorize('risks.manage');

        $this->reset(['title', 'description', 'riskOwnerId']);
        $this->resetValidation();
        $this->category = 'operational';
        $this->likelihood = '3';
        $this->impact = '3';
        $this->treatment = 'mitigate';
        $this->reviewIntervalMonths = '6';
        $this->adding = true;
    }

    public function cancel(): void
    {
        $this->adding = false;
        $this->addingControlTo = null;
        $this->reassessing = null;
        $this->reviewing = null;
        $this->closing = null;
        $this->resetValidation();
    }

    public function raise(): void
    {
        Gate::authorize('risks.manage');

        $this->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'in:'.implode(',', array_keys(Risk::CATEGORIES))],
            'likelihood' => ['required', 'numeric', 'min:1', 'max:5'],
            'impact' => ['required', 'numeric', 'min:1', 'max:5'],
            'treatment' => ['required', 'in:'.implode(',', array_keys(Risk::TREATMENTS))],
            'reviewIntervalMonths' => ['nullable', 'numeric', 'min:1', 'max:60'],
        ], [
            'title.required' => 'What could go wrong?',
        ]);

        $interval = $this->reviewIntervalMonths === '' ? null : (int) $this->reviewIntervalMonths;

        try {
            app(RiskRegister::class)->record([
                'title' => $this->title,
                'description' => $this->description ?: null,
                'category' => $this->category,
                'likelihood' => (int) $this->likelihood,
                'impact' => (int) $this->impact,
                'treatment' => $this->treatment,
                'owner_id' => $this->riskOwnerId ?: auth()->id(),
                'review_interval_months' => $interval,
                // A risk with no review date is one nobody will look at again.
                'next_review_on' => $interval ? now()->addMonths($interval)->toDateString() : null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('risk', $e->getMessage());

            return;
        }

        $this->adding = false;
        session()->flash('status', 'Risk added to the register.');
    }

    public function startControl(string $riskId): void
    {
        Gate::authorize('risks.manage');

        $this->addingControlTo = $riskId;
        $this->open = $riskId;
        $this->controlTitle = '';
        $this->controlKind = 'preventive';
        $this->controlDueOn = now()->addMonth()->toDateString();
        $this->controlOwnerId = null;
        $this->resetValidation();
    }

    public function addControl(): void
    {
        Gate::authorize('risks.manage');

        $this->validate([
            'controlTitle' => ['required', 'string', 'max:180'],
            'controlKind' => ['required', 'in:'.implode(',', array_keys(RiskControl::KINDS))],
            'controlDueOn' => ['nullable', 'date'],
        ], [
            'controlTitle.required' => 'What is being done about it?',
        ]);

        $risk = Risk::findOrFail($this->addingControlTo);

        try {
            app(RiskRegister::class)->addControl($risk, [
                'title' => $this->controlTitle,
                'kind' => $this->controlKind,
                'due_on' => $this->controlDueOn ?: null,
                'owner_id' => $this->controlOwnerId ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('control', $e->getMessage());

            return;
        }

        $this->addingControlTo = null;

        // Said plainly, because the alternative belief is the dangerous one.
        session()->flash('status', 'Control recorded. It does not change the score — a reassessment does.');
    }

    public function markControlInPlace(string $controlId): void
    {
        Gate::authorize('risks.manage');

        try {
            app(RiskRegister::class)->markControlInPlace(RiskControl::findOrFail($controlId));
        } catch (RuntimeException $e) {
            $this->addError('control', $e->getMessage());

            return;
        }

        session()->flash('status', 'Marked as in place.');
    }

    /**
     * The control was tried and it is not doing the job — the loudest honest
     * thing the register can say about a mitigation.
     */
    public function markControlFailed(string $controlId): void
    {
        Gate::authorize('risks.manage');

        try {
            app(RiskRegister::class)->markControlFailed(RiskControl::findOrFail($controlId));
        } catch (RuntimeException $e) {
            $this->addError('control', $e->getMessage());

            return;
        }

        session()->flash('status', 'Marked as not working. The risk it was treating deserves another look.');
    }

    public function startClosing(string $riskId): void
    {
        Gate::authorize('risks.manage');

        $this->closing = $riskId;
        $this->open = $riskId;
        $this->closureReason = '';
        $this->resetValidation();
    }

    public function closeRisk(): void
    {
        Gate::authorize('risks.manage');

        $this->validate(
            ['closureReason' => ['required', 'string', 'max:500']],
            ['closureReason.required' => 'Why is this no longer on the register? The reason is the record.'],
        );

        $risk = Risk::findOrFail($this->closing);

        try {
            app(RiskRegister::class)->close($risk, $this->closureReason, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('closing', $e->getMessage());

            return;
        }

        $this->closing = null;
        $this->open = null;
        session()->flash('status', 'Risk closed, with the reason kept.');
    }

    public function reopenRisk(string $riskId): void
    {
        Gate::authorize('risks.manage');

        $risk = Risk::findOrFail($riskId);

        try {
            app(RiskRegister::class)->reopen($risk);
        } catch (RuntimeException $e) {
            $this->addError('risk', $e->getMessage());

            return;
        }

        session()->flash('status', 'Back on the register. Give it a review date so it is not forgotten again.');
    }

    /**
     * Reassessing is `risks.review`, not `risks.manage`.
     *
     * Deliberately not held by the person who owns the risk: letting somebody
     * quietly mark their own risk down turns the register into a list of things
     * that used to worry people.
     */
    public function startReassessing(string $riskId): void
    {
        Gate::authorize('risks.review');

        $risk = Risk::findOrFail($riskId);

        $this->reassessing = $riskId;
        $this->open = $riskId;
        $this->residualLikelihood = (string) ($risk->residual_likelihood ?? $risk->likelihood);
        $this->residualImpact = (string) ($risk->residual_impact ?? $risk->impact);
        $this->resetValidation();
    }

    public function reassess(): void
    {
        Gate::authorize('risks.review');

        $risk = Risk::findOrFail($this->reassessing);

        try {
            app(RiskRegister::class)->reassess(
                $risk,
                (int) $this->residualLikelihood,
                (int) $this->residualImpact,
            );
        } catch (RuntimeException $e) {
            $this->addError('reassessing', $e->getMessage());

            return;
        }

        $this->reassessing = null;
        session()->flash('status', 'Residual score recorded.');
    }

    public function startReview(string $riskId): void
    {
        Gate::authorize('risks.review');

        $this->reviewing = $riskId;
        $this->open = $riskId;
        $this->reviewedOn = now()->toDateString();
        $this->reviewNotes = '';
        $this->resetValidation();
    }

    public function review(): void
    {
        Gate::authorize('risks.review');

        $this->validate([
            'reviewedOn' => ['required', 'date'],
            'reviewNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        $risk = Risk::findOrFail($this->reviewing);

        try {
            app(RiskRegister::class)->review(
                $risk,
                Carbon::parse($this->reviewedOn),
                $this->reviewNotes ?: null,
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('reviewing', $e->getMessage());

            return;
        }

        $this->reviewing = null;
        session()->flash('status', 'Reviewed. The next review date has moved on.');
    }

    public function toggle(string $riskId): void
    {
        $this->open = $this->open === $riskId ? null : $riskId;
    }

    public function render(): View
    {
        $calendar = app(ComplianceCalendar::class);
        $company = app(CurrentCompany::class)->get();

        return view('livewire.risks.index', [
            'summary' => $calendar->summary(),
            'risks' => Risk::query()
                ->open()
                ->with(['owner', 'controls.owner'])
                ->mostSevereFirst()
                ->get(),
            // Recently closed, so a closure is visible and reversible rather
            // than a disappearance.
            'closedRisks' => Risk::query()
                ->where('status', 'closed')
                ->latest('closed_on')
                ->limit(10)
                ->get(),
            'toReview' => $calendar->risksDueForReview(),
            'overdueControls' => $calendar->overdueControls(),
            'categories' => Risk::CATEGORIES,
            'treatments' => Risk::TREATMENTS,
            'kinds' => RiskControl::KINDS,
            'people' => $company?->users()->orderBy('name')->get(['users.id', 'users.name']) ?? collect(),
        ])->layout('components.layouts.app', [
            'title' => 'Risk register',
            'active' => 'risks',
        ]);
    }
}
