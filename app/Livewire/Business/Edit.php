<?php

namespace App\Livewire\Business;

use App\Enums\TaxRegime;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\VerificationToken;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class Edit extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    /** @var array<string, mixed> */
    public array $form = [];

    public $logoUpload = null;

    // Loyalty (start) — kept separate from $form/save() so toggling the
    // program on or off never risks the rest of the business profile form.
    public bool $loyaltyEnabled = false;

    public string $loyaltyPointsPerAmount = '100';

    public string $loyaltyPointValue = '1';

    public function saveLoyaltySettings(): void
    {
        $this->authorize('loyalty.manage');

        $this->validate([
            'loyaltyPointsPerAmount' => ['required', 'numeric', 'min:0.01'],
            'loyaltyPointValue' => ['required', 'numeric', 'min:0'],
        ]);

        app(CurrentCompany::class)->get()->update([
            'loyalty_enabled' => $this->loyaltyEnabled,
            'loyalty_points_per_amount' => $this->loyaltyPointsPerAmount,
            'loyalty_point_value' => $this->loyaltyPointValue,
        ]);

        session()->flash('loyaltyStatus', 'Loyalty settings saved.');
    }
    // Loyalty (end)

    public function mount(): void
    {
        $company = app(CurrentCompany::class)->get();

        $this->loyaltyEnabled = (bool) $company->loyalty_enabled;
        $this->loyaltyPointsPerAmount = (string) $company->loyalty_points_per_amount;
        $this->loyaltyPointValue = (string) $company->loyalty_point_value;

        $this->form = [
            'name' => $company->name,
            'motto' => $company->motto,
            'description' => $company->description,
            'industry' => $company->industry,
            'registration_number' => $company->registration_number,
            'tax_id' => $company->tax_id,
            'vat_number' => $company->vat_number,
            'tax_regime' => $company->tax_regime,
            'tax_centre' => $company->tax_centre,
            'capital_social' => $company->capital_social,
            // Payroll. Kept beside the fiscal identity because that is what
            // they are: the business's registration with the CNPS, and the
            // two classifications the CNPS assigns it.
            'cnps_employer_number' => $company->cnps_employer_number,
            'cnps_risk_group' => $company->cnps_risk_group ?: 'a',
            'cnps_family_regime' => $company->cnps_family_regime ?: 'general',
            'vat_registered' => (bool) $company->vat_registered,
            'vat_rate' => (string) ($company->vat_rate ?: '19.25'),
            'prices_include_tax' => (bool) $company->prices_include_tax,
            'default_sales_account' => $company->default_sales_account ?: 'sales_goods',
            'email' => $company->email,
            'website' => $company->website,
            'phone1' => data_get($company->phones, 0),
            'phone2' => data_get($company->phones, 1),
            'address_line1' => $company->address_line1,
            'city' => $company->city,
            'region' => $company->region,
            'country' => $company->country,
            'currency' => $company->currency,
            'facebook' => data_get($company->socials, 'facebook'),
            'instagram' => data_get($company->socials, 'instagram'),
            'x' => data_get($company->socials, 'x'),
            'linkedin' => data_get($company->socials, 'linkedin'),
            'whatsapp' => data_get($company->socials, 'whatsapp'),
        ];
    }

    public function save(): void
    {
        $this->authorize('business.update');

        $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.motto' => ['nullable', 'string', 'max:160'],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.industry' => ['nullable', 'string', 'max:60'],
            'form.email' => ['nullable', 'email', 'max:120'],
            'form.website' => ['nullable', 'string', 'max:160'],
            'form.currency' => ['required', 'in:'.implode(',', config('opes.currencies'))],
            'form.country' => ['nullable', 'string', 'max:2'],
            'form.tax_regime' => ['nullable', Rule::enum(TaxRegime::class)],
            'form.tax_centre' => ['nullable', 'string', 'max:120'],
            'form.capital_social' => ['nullable', 'numeric', 'min:0'],
            'form.cnps_employer_number' => ['nullable', 'string', 'max:40'],
            'form.cnps_risk_group' => ['required', 'in:'.implode(',', array_keys(config('payroll.cnps.occupational_risk.groups')))],
            'form.cnps_family_regime' => ['required', 'in:general,agricultural,teaching'],
            // Capped well above any real rate: a typo of 1925 for 19.25 would
            // otherwise multiply every invoice in the business by twenty.
            'form.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'form.default_sales_account' => ['required', 'in:sales_goods,sales_services'],
            'logoUpload' => ['nullable', 'image', 'max:4096'],
        ]);

        $company = app(CurrentCompany::class)->get();

        if ($this->logoUpload) {
            $company->logo_path = $this->logoUpload->store('logos/'.$company->id, 'public');
            $this->logoUpload = null;
        }

        $company->fill([
            'name' => trim($this->form['name']),
            'motto' => $this->form['motto'] ?: null,
            'description' => $this->form['description'] ?: null,
            'industry' => $this->form['industry'] ?: null,
            'registration_number' => $this->form['registration_number'] ?: null,
            'tax_id' => $this->form['tax_id'] ?: null,
            'tax_id_index' => $this->form['tax_id']
                ? hash('sha256', preg_replace('/\W+/', '', $this->form['tax_id']))
                : null,
            'vat_number' => $this->form['vat_number'] ?: null,
            'tax_regime' => $this->form['tax_regime'] ?: null,
            'tax_centre' => $this->form['tax_centre'] ?: null,
            'capital_social' => $this->form['capital_social'] !== '' ? $this->form['capital_social'] : null,
            'cnps_employer_number' => $this->form['cnps_employer_number'] ?: null,
            'cnps_risk_group' => $this->form['cnps_risk_group'],
            'cnps_family_regime' => $this->form['cnps_family_regime'],
            'vat_registered' => (bool) $this->form['vat_registered'],
            'vat_rate' => $this->form['vat_rate'] !== '' ? $this->form['vat_rate'] : 19.25,
            'prices_include_tax' => (bool) $this->form['prices_include_tax'],
            'default_sales_account' => $this->form['default_sales_account'],
            'email' => $this->form['email'] ?: null,
            'website' => $this->form['website'] ?: null,
            'phones' => array_values(array_filter([$this->form['phone1'], $this->form['phone2']])),
            'address_line1' => $this->form['address_line1'] ?: null,
            'city' => $this->form['city'] ?: null,
            'region' => $this->form['region'] ?: null,
            'country' => $this->form['country'] ? strtoupper($this->form['country']) : null,
            'currency' => $this->form['currency'],
            'socials' => array_filter([
                'facebook' => $this->form['facebook'] ?: null,
                'instagram' => $this->form['instagram'] ?: null,
                'x' => $this->form['x'] ?: null,
                'linkedin' => $this->form['linkedin'] ?: null,
                'whatsapp' => $this->form['whatsapp'] ?: null,
            ]),
        ])->save();

        $this->dispatch('saved');
        session()->flash('status', 'Business profile updated.');
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        return view('livewire.business.edit', [
            'company' => $company,
            'businessToken' => $this->businessToken($company),
            'pendingReviews' => CompanyReview::query()->where('is_published', false)->count(),
        ])->layout('components.layouts.app', ['title' => 'Business', 'active' => 'business']);
    }

    /**
     * The company's permanent public identity token — created on first visit so
     * every business has a QR the moment it looks for one.
     */
    protected function businessToken(Company $company): VerificationToken
    {
        return VerificationToken::firstOrCreate(
            ['subject_type' => Company::class, 'subject_id' => $company->id],
            ['token' => VerificationToken::newToken(), 'company_id' => $company->id],
        );
    }
}
