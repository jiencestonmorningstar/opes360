<?php

namespace App\Livewire\Audit;

use App\Support\AuditRetention;
use App\Support\CurrentCompany;
use App\Support\SegregationOfDuties;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Who holds what, and which combinations should not sit together.
 *
 * The permission model has always been correct on paper — Permissions.php
 * argues every separation and the seeder honours it. What nobody could see is
 * what the grants look like after a year of "just give Blaise approve as well,
 * he's covering while Awa is away". This screen is that view.
 */
class Governance extends Component
{
    #[Url]
    public string $tab = 'conflicts'; // conflicts|matrix|retention

    /** How many months of trail this business keeps. See AuditRetention for the floors. */
    public ?int $retentionMonths = null;

    public function mount(): void
    {
        Gate::authorize('audit.govern');

        $this->retentionMonths = app(CurrentCompany::class)->get()?->audit_retention_months
            ?? AuditRetention::DEFAULT_MONTHS;
    }

    /**
     * The floor is validated here and enforced again in AuditRetention: a
     * number smuggled past this form still cannot shorten what the pruner
     * actually keeps. The company update itself lands in the trail through
     * the ordinary observer, so choosing a shorter retention is on record.
     */
    public function saveRetention(): void
    {
        Gate::authorize('audit.govern');

        $this->validate(
            ['retentionMonths' => ['required', 'integer', 'min:'.AuditRetention::FLOOR_ACCESS_MONTHS, 'max:600']],
            ['retentionMonths.min' => 'The trail is kept for at least '.AuditRetention::FLOOR_ACCESS_MONTHS.' months.'],
        );

        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return;
        }

        $company->forceFill(['audit_retention_months' => $this->retentionMonths])->save();

        $this->dispatch('saved');
    }

    public function render(): View
    {
        Gate::authorize('audit.govern');

        $company = app(CurrentCompany::class)->get();

        return view('livewire.audit.governance', [
            'findings' => $company ? SegregationOfDuties::findings($company)->groupBy(fn ($f) => $f['rule']['key']) : collect(),
            'unavoidable' => $company ? SegregationOfDuties::unavoidable($company)->pluck('user')->unique('id') : collect(),
            'holdings' => $company && $this->tab === 'matrix' ? SegregationOfDuties::holdings($company) : collect(),
            'rules' => SegregationOfDuties::RULES,
        ])->layout('components.layouts.app', ['title' => 'Governance', 'active' => 'settings']);
    }
}
