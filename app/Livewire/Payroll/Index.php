<?php

namespace App\Livewire\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollRunner;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The months.
 *
 * Payroll is the one part of this system that is genuinely periodic — there is
 * no such thing as an ad-hoc payslip — so the list is a list of months, and
 * starting one is the only action here.
 */
class Index extends Component
{
    use WithPagination;

    public string $period = '';

    public function mount(): void
    {
        Gate::authorize('payroll.view');

        $this->period = now()->startOfMonth()->toDateString();
    }

    /**
     * Open a month and build it in one step.
     *
     * Two buttons would be one too many: a run with no payslips in it is not
     * a thing anybody wants, it is a thing they forgot to press the second
     * button on.
     */
    public function start(): void
    {
        Gate::authorize('payroll.run');

        $this->validate(['period' => ['required', 'date']]);

        $runner = app(PayrollRunner::class);
        $run = $runner->open($this->period, auth()->user());

        if ($run->isEditable()) {
            try {
                $runner->build($run, auth()->user());
            } catch (RuntimeException $e) {
                session()->flash('error', $e->getMessage());

                return;
            }
        }

        $this->redirectRoute('payroll.show', $run, navigate: true);
    }

    /**
     * The months worth offering: this one and the eighteen behind it.
     *
     * A picker rather than a date field because "which month" is the actual
     * question, and a date input would let someone build the payroll for the
     * 14th of August, which is not a thing.
     *
     * @return array<string, string>
     */
    protected function months(): array
    {
        $months = [];

        for ($i = 0; $i < 18; $i++) {
            $month = now()->startOfMonth()->subMonths($i);
            $months[$month->toDateString()] = $month->translatedFormat('F Y');
        }

        return $months;
    }

    public function render(): View
    {
        $runs = PayrollRun::query()
            ->orderByDesc('period')
            ->paginate(12);

        $lastPaid = PayrollRun::query()
            ->where('status', 'paid')
            ->orderByDesc('period')
            ->first();

        return view('livewire.payroll.index', [
            'runs' => $runs,
            'months' => $this->months(),
            'lastPaid' => $lastPaid,
            'headcount' => Employee::query()->where('status', 'active')->count(),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => 'Payroll', 'active' => 'payroll']);
    }
}
