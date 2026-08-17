<?php

namespace App\Livewire\Compliance;

use App\Models\BusinessDocument;
use App\Models\ComplianceFiling;
use App\Models\ComplianceObligation;
use App\Services\Compliance\ComplianceRegister;
use App\Support\ComplianceCalendar;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The compliance calendar: what the business owes the state, and by when.
 *
 * Overdue is the reason anybody opens this screen, so it is counted at the top
 * and listed first. Everything else on the page is context for that one number.
 */
class Index extends Component
{
    #[Url]
    public string $tab = 'due'; // due|register|filed

    /** Which obligation's past filings are expanded. */
    public ?string $showingHistory = null;

    // ── Filing form ─────────────────────────────────────────────────────
    /** The open filing being recorded, if any. */
    public ?string $filing = null;

    /** Its obligation, held so the row can show the form without a query each. */
    public ?string $filingObligation = null;

    public string $completedOn = '';

    public string $periodLabel = '';

    public string $reference = '';

    public string $amount = '';

    public string $filingNotes = '';

    public ?string $evidenceId = null;

    // ── New obligation ──────────────────────────────────────────────────
    public bool $adding = false;

    public string $name = '';

    public string $category = 'tax';

    public string $authority = '';

    public string $intervalMonths = '3';

    public string $scheduleBasis = 'due';

    public string $nextDueOn = '';

    public string $leadDays = '14';

    public ?string $obligationOwnerId = null;

    public bool $requiresApproval = false;

    public function mount(): void
    {
        Gate::authorize('compliance.view');

        $this->nextDueOn = now()->addMonth()->toDateString();
    }

    public function startAdding(): void
    {
        Gate::authorize('compliance.manage');

        $this->reset(['name', 'authority', 'obligationOwnerId', 'requiresApproval']);
        $this->resetValidation();
        $this->category = 'tax';
        $this->intervalMonths = '3';
        $this->scheduleBasis = 'due';
        $this->leadDays = '14';
        $this->nextDueOn = now()->addMonth()->toDateString();
        $this->adding = true;
    }

    public function cancel(): void
    {
        $this->adding = false;
        $this->filing = null;
        $this->filingObligation = null;
        $this->resetValidation();
    }

    /**
     * Define a standing duty.
     *
     * Written straight to the model because there is no service method for
     * defining an obligation — the register's job starts at the first filing.
     * Everything that changes a filing's state below goes through the service.
     */
    public function addObligation(): void
    {
        Gate::authorize('compliance.manage');

        $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'in:'.implode(',', array_keys(ComplianceObligation::CATEGORIES))],
            'authority' => ['nullable', 'string', 'max:120'],
            'intervalMonths' => ['nullable', 'numeric', 'min:1', 'max:120'],
            'scheduleBasis' => ['required', 'in:due,completion'],
            'nextDueOn' => ['required', 'date'],
            'leadDays' => ['required', 'numeric', 'min:0', 'max:365'],
        ], [
            'name.required' => 'What is the duty called?',
            'nextDueOn.required' => 'When is the next one due?',
        ]);

        ComplianceObligation::create([
            'name' => $this->name,
            'category' => $this->category,
            'authority' => $this->authority ?: null,
            'interval_months' => $this->intervalMonths === '' ? null : (int) $this->intervalMonths,
            'schedule_basis' => $this->scheduleBasis,
            'next_due_on' => $this->nextDueOn,
            'lead_days' => (int) $this->leadDays,
            'owner_id' => $this->obligationOwnerId ?: null,
            'requires_approval' => $this->requiresApproval,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        $this->adding = false;
        session()->flash('status', 'Obligation added to the calendar.');
    }

    /**
     * Open the occurrence that is due, then show the form against it.
     *
     * Raising is idempotent in the service, so two people reaching for the
     * same quarter end up on the same filing rather than declaring it twice.
     */
    public function startFiling(string $obligationId): void
    {
        Gate::authorize('compliance.file');

        $obligation = ComplianceObligation::findOrFail($obligationId);

        $filing = app(ComplianceRegister::class)->raise($obligation);

        $this->filing = $filing->id;
        $this->filingObligation = $obligation->id;
        $this->completedOn = now()->toDateString();
        $this->periodLabel = $filing->period_label ?? '';
        $this->reference = $filing->reference ?? '';
        $this->amount = '';
        $this->filingNotes = '';
        $this->evidenceId = null;
        $this->resetValidation();
    }

    /**
     * Record that it went in.
     *
     * The service decides whether that means "done" or "waiting on sign-off";
     * this screen only reports back what it decided.
     */
    public function file(): void
    {
        Gate::authorize('compliance.file');

        $this->validate([
            'completedOn' => ['required', 'date'],
            'periodLabel' => ['nullable', 'string', 'max:60'],
            'reference' => ['nullable', 'string', 'max:80'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'filingNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        $filing = ComplianceFiling::findOrFail($this->filing);
        $register = app(ComplianceRegister::class);

        try {
            $filing = $register->submit($filing, auth()->user(), [
                'period_label' => $this->periodLabel ?: null,
                'reference' => $this->reference ?: null,
                'amount' => $this->amount === '' ? null : (float) $this->amount,
                'notes' => $this->filingNotes ?: null,
                'completed_on' => Carbon::parse($this->completedOn),
            ]);

            if ($this->evidenceId) {
                $register->attachEvidence(
                    $filing,
                    BusinessDocument::findOrFail($this->evidenceId),
                    auth()->user(),
                );
            }
        } catch (RuntimeException $e) {
            $this->addError('filing', $e->getMessage());

            return;
        }

        $this->filing = null;
        $this->filingObligation = null;

        session()->flash('status', $filing->isDone()
            ? 'Filed. The next deadline has moved on.'
            : 'Sent for sign-off. It counts as filed once it is approved.');
    }

    /**
     * A refused filing goes back to being prepared, so it can be corrected
     * and refiled. The deadline the refusal was about has not moved.
     */
    public function reprepare(string $filingId): void
    {
        Gate::authorize('compliance.file');

        $filing = ComplianceFiling::findOrFail($filingId);

        try {
            app(ComplianceRegister::class)->returnToPreparer($filing);
        } catch (RuntimeException $e) {
            $this->addError('refused', $e->getMessage());

            return;
        }

        session()->flash('status', 'Back with the preparer. Correct it and file it again.');
    }

    public function toggleHistory(string $obligationId): void
    {
        $this->showingHistory = $this->showingHistory === $obligationId ? null : $obligationId;
    }

    public function render(): View
    {
        $calendar = app(ComplianceCalendar::class);
        $company = app(CurrentCompany::class)->get();

        return view('livewire.compliance.index', [
            'summary' => $calendar->summary(),
            'overdue' => $calendar->overdue()->load('owner'),
            'dueSoon' => $calendar->dueSoon()->load('owner'),
            'inProgress' => $calendar->inProgress(),
            // Refused filings need a way back — listed so they can be sent
            // back to the preparer rather than sitting as a dead end.
            'refused' => ComplianceFiling::query()
                ->where('status', 'rejected')
                ->with('obligation')
                ->latest('updated_at')
                ->get(),
            'obligations' => ComplianceObligation::query()
                ->with(['owner', 'filings'])
                ->orderByRaw('next_due_on IS NULL, next_due_on')
                ->get(),
            'recentlyFiled' => ComplianceFiling::query()
                ->where('status', 'completed')
                ->with('obligation')
                ->latest('completed_on')
                ->limit(20)
                ->get(),
            'categories' => ComplianceObligation::CATEGORIES,
            'bases' => ComplianceObligation::SCHEDULE_BASES,
            'people' => $company?->users()->orderBy('name')->get(['users.id', 'users.name']) ?? collect(),
            // Evidence is an ordinary document — this screen only points at one.
            'documents' => BusinessDocument::query()
                ->latest('created_at')
                ->limit(100)
                ->get(['id', 'title']),
        ])->layout('components.layouts.app', [
            'title' => 'Compliance calendar',
            'active' => 'compliance',
        ]);
    }
}
