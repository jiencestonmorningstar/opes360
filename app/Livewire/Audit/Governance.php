<?php

namespace App\Livewire\Audit;

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
    public string $tab = 'conflicts'; // conflicts|matrix

    public function mount(): void
    {
        Gate::authorize('audit.govern');
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
