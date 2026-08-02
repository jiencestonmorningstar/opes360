<?php

namespace App\Livewire\Payroll;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Payroll\PayrollRunner;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * One month, person by person.
 *
 * The screen's real job is the column nobody expects: what the month actually
 * costs. A business owner knows the gross because they agreed it; the
 * employer's CNPS, CFC and FNE on top of it are a fifth again, and almost
 * nobody has that number to hand before they see it here.
 */
class Show extends Component
{
    public PayrollRun $run;

    public string $payMethod = 'bank';

    public string $payReference = '';

    public string $paidOn = '';

    public bool $paying = false;

    public function mount(PayrollRun $run): void
    {
        Gate::authorize('payroll.view');

        $this->run = $run;
        $this->paidOn = ($run->pay_date ?? now())->toDateString();
    }

    public function rebuild(): void
    {
        Gate::authorize('payroll.run');

        try {
            app(PayrollRunner::class)->build($this->run, auth()->user());
            $this->run->refresh();
            session()->flash('status', 'Rebuilt from the current contracts and allowances.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function approve(): void
    {
        Gate::authorize('payroll.approve');

        try {
            $this->run = app(PayrollRunner::class)->approve($this->run, auth()->user());
            session()->flash('status', 'Approved and posted to the books.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function startPaying(): void
    {
        Gate::authorize('payroll.pay');

        $this->paidOn = now()->toDateString();
        $this->paying = true;
    }

    public function markPaid(): void
    {
        Gate::authorize('payroll.pay');

        $this->validate([
            'paidOn' => ['required', 'date'],
            'payMethod' => ['required', 'in:cash,mobile_money,bank,cheque'],
            'payReference' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $this->run = app(PayrollRunner::class)->markPaid($this->run, [
                'method' => $this->payMethod,
                'paid_on' => $this->paidOn,
                'reference' => $this->payReference ?: null,
            ], auth()->user());

            $this->paying = false;
            session()->flash('status', 'Recorded as paid.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function void(): void
    {
        Gate::authorize('payroll.void');

        try {
            $this->run = app(PayrollRunner::class)->void($this->run, auth()->user());
            session()->flash('status', 'Voided. Any journal entry has been reversed, not deleted.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Only worth saying when it actually applies: the levy was withheld from
     * somebody on this run, and nobody has confirmed the bands. A warning on a
     * run that withheld nothing is noise, and noise is how a real warning gets
     * ignored.
     *
     * @param  Collection<int, Payslip>  $payslips
     */
    protected function ravIsUnverified($payslips): bool
    {
        $settings = (array) (app(CurrentCompany::class)->get()?->payroll_settings ?? []);

        if (($settings['rav_confirmed'] ?? false) === true) {
            return false;
        }

        return $payslips->contains(fn (Payslip $slip) => (float) $slip->rav > 0);
    }

    public function render(): View
    {
        $payslips = Payslip::query()
            ->where('payroll_run_id', $this->run->id)
            ->with('employee:id,first_name,last_name,job_title,payment_method')
            ->get();

        return view('livewire.payroll.show', [
            'payslips' => $payslips,
            // The declaration totals, which is what these figures are for: one
            // number for the CNPS and one for the DGI, both of which are due
            // whether or not anyone has worked them out.
            'cnpsDue' => round(
                (float) $payslips->sum(fn (Payslip $p) => (float) $p->cnps_employee
                    + (float) $p->cnps_employer_pension
                    + (float) $p->cnps_employer_family
                    + (float) $p->cnps_employer_risk),
                2
            ),
            'taxDue' => round(
                (float) $payslips->sum(fn (Payslip $p) => (float) $p->irpp + (float) $p->cac
                    + (float) $p->cfc_employee + (float) $p->cfc_employer
                    + (float) $p->tdl + (float) $p->rav + (float) $p->fne),
                2
            ),
            /*
             * Whether this run withheld an audiovisual levy on a scale nobody
             * has checked. Said here rather than only in a config comment,
             * because this is the screen where money is about to leave an
             * employee's wages and the last moment anyone would want to know.
             */
            'ravUnverified' => $this->ravIsUnverified($payslips),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', [
            'title' => 'Payroll — '.$this->run->periodLabel(),
            'active' => 'payroll',
        ]);
    }
}
