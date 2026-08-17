<?php

namespace App\Livewire\Business;

use App\Models\Company;
use App\Models\Role;
use App\Models\VerificationToken;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use App\Support\DefaultWorkflows;
use App\Support\Sectors;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Module 14 — multiple businesses under one account.
 *
 * Each company is a separate tenant: separate records, branding, documents and
 * numbering. Switching only changes which company the session acts as; it never
 * merges data.
 */
class Companies extends Component
{
    public bool $creating = false;

    public string $newName = '';

    public string $newIndustry = '';

    public string $newCurrency = 'USD';

    /**
     * Optional, same as at signup: the sector guides the new business's
     * starting module set and locks nothing. Defaults to 'everything'.
     */
    public string $newSector = Sectors::EVERYTHING;

    public function switchTo(string $companyId): void
    {
        $user = auth()->user();
        $company = $user->activeMemberships()->where('companies.id', $companyId)->first();

        // Membership is re-checked here rather than trusted from the request, so
        // a crafted id cannot switch into someone else's business — and it has
        // to be an *active* membership, the same test the list below renders
        // from, or a crafted id could still reach a business this user was
        // removed from.
        if ($company === null) {
            $this->addError('switch', 'You do not have access to that business.');

            return;
        }

        $user->forceFill(['current_company_id' => $company->id])->save();

        $this->redirectRoute('dashboard');
    }

    public function startCreating(): void
    {
        $this->creating = true;
        $this->newCurrency = app(CurrentCompany::class)->get()?->currency ?? 'USD';
    }

    public function cancelCreating(): void
    {
        $this->reset('creating', 'newName', 'newIndustry', 'newSector');
    }

    public function createCompany(): void
    {
        $this->validate([
            'newName' => ['required', 'string', 'max:120'],
            'newIndustry' => ['nullable', 'string', 'max:60'],
            'newCurrency' => ['required', 'in:'.implode(',', config('opes.currencies'))],
        ], [
            'newName.required' => 'Give the business a name.',
        ]);

        $user = auth()->user();

        $company = DB::transaction(function () use ($user) {
            $company = Company::create([
                'slug' => $this->uniqueSlug($this->newName),
                'name' => trim($this->newName),
                'industry' => $this->newIndustry ?: null,
                'currency' => $this->newCurrency,
                'owner_id' => $user->id,
            ]);

            $company->users()->attach($user->id, [
                'role_id' => Role::where('slug', Role::OWNER)->value('id'),
                'job_title' => 'Business Owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            app(CurrentCompany::class)->as($company, fn () => VerificationToken::create([
                'token' => VerificationToken::newToken(),
                'subject_type' => Company::class,
                'subject_id' => $company->id,
            ]));

            // The books start the day the business does. Seeded here rather
            // than lazily so an accountant opening the chart on day one finds
            // something to work from, and so the first invoice has somewhere
            // to post to.
            ChartOfAccounts::seed($company);
            // Without these, every submit-for-approval in the product refuses:
            // the engine is data-driven and a business with no rows has no path.
            DefaultWorkflows::seed($company);

            // Applied once, here — the sector is never re-read afterwards.
            Sectors::apply($company, $this->newSector);

            return $company;
        });

        $user->forceFill(['current_company_id' => $company->id])->save();

        $this->redirectRoute('business');
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;

        while (Company::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.business.companies', [
            'companies' => $user->activeMemberships()->orderBy('name')->get(),
            'currentId' => $user->current_company_id,
        ])->layout('components.layouts.app', ['title' => 'Businesses', 'active' => 'business']);
    }
}
