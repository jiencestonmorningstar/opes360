<?php

namespace App\Livewire\Payables;

use App\Services\Payables\PaymentScheduler;
use App\Support\CurrentCompany;
use App\Support\PaymentSchedule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Which bills this week's cash actually reaches.
 *
 * The aging report already says which bills are late. It cannot say which of
 * them get paid, because that depends on money the report knows nothing about.
 * So the two numbers a person has in their head — what is in the bank, and what
 * must not be spent — are the two fields at the top of this screen, and every
 * fund/part/defer mark below moves when they do.
 */
class Schedule extends Component
{
    /**
     * Blank means "use the forecast". Kept as a string rather than a float so
     * an empty box stays empty: a zero typed in by the framework reads as a
     * business with no money, which is a very different claim.
     */
    #[Url]
    public string $cash = '';

    #[Url]
    public string $reserve = '0';

    #[Url]
    public string $payOn = '';

    /** Where "due soon" stops and "later" begins. */
    #[Url]
    public int $horizonDays = 14;

    /** Whether the deferred tail is on screen or folded away. */
    public bool $showDeferred = false;

    // ── Building a run out of the plan ──────────────────────────────────
    public bool $building = false;

    public string $runReference = '';

    public string $runMethod = 'bank';

    public string $runNotes = '';

    public function mount(): void
    {
        Gate::authorize('payables.view');

        $this->payOn = $this->payOn ?: now()->toDateString();
    }

    public function startBuilding(): void
    {
        Gate::authorize('payables.manage');

        $this->resetValidation();
        $this->building = true;
    }

    /**
     * Freeze the plan into a draft run.
     *
     * Nothing is paid here and nothing is approved. A plan is a photograph that
     * changes every time the forecast moves; a run is the version somebody is
     * prepared to put their name against.
     */
    public function buildRun(): void
    {
        Gate::authorize('payables.manage');

        $this->validateInputs();

        try {
            $run = app(PaymentScheduler::class)->buildRun($this->schedule(), [
                'scheduled_for' => $this->payOn,
                'method' => $this->runMethod,
                'cash' => $this->cashOrNull(),
                'reference' => $this->runReference ?: null,
                'notes' => $this->runNotes ?: null,
            ], auth()->user());

            $this->building = false;
            $this->reset(['runReference', 'runNotes']);

            session()->flash('status', 'Draft run built with '.$run->items->count().
                ' bills on it. Nothing has been paid — it still has to be approved, then released, under Payment runs.');
        } catch (RuntimeException $e) {
            $this->addError('runReference', $e->getMessage());
        }
    }

    protected function schedule(): PaymentSchedule
    {
        return new PaymentSchedule(
            app(CurrentCompany::class)->get(),
            Carbon::parse($this->payOn ?: now()->toDateString()),
            (float) ($this->reserve === '' ? 0 : $this->reserve),
            max(1, $this->horizonDays),
        );
    }

    protected function cashOrNull(): ?float
    {
        return trim($this->cash) === '' ? null : (float) $this->cash;
    }

    protected function validateInputs(): void
    {
        $this->validate([
            'cash' => ['nullable', 'numeric', 'min:0'],
            'reserve' => ['required', 'numeric', 'min:0'],
            'payOn' => ['required', 'date'],
        ], [
            'reserve.required' => 'Set a reserve, even if it is zero — this is the money the plan is forbidden to touch.',
            'cash.min' => 'Cash in hand cannot be negative. If the account is overdrawn there is nothing to schedule.',
        ]);
    }

    public function render(): View
    {
        $plan = $this->schedule()->plan($this->cashOrNull());

        $items = collect($plan['items']);

        return view('livewire.payables.schedule', [
            'plan' => $plan,
            // Split rather than filtered in the view: the funded lines are the
            // decision and the deferred tail is the consequence, and mixing
            // them in one list hides which is which.
            'funded' => $items->reject(fn (array $i) => $i['decision'] === PaymentSchedule::DECISION_DEFER)->values(),
            'deferred' => $items->where('decision', PaymentSchedule::DECISION_DEFER)->values(),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Payment schedule', 'active' => 'payables']);
    }
}
