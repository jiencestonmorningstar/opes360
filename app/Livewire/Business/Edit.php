<?php

namespace App\Livewire\Business;

use App\Models\Company;
use App\Models\VerificationToken;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

class Edit extends Component
{
    use WithFileUploads;

    /** @var array<string, mixed> */
    public array $form = [];

    public $logoUpload = null;

    public function mount(): void
    {
        $company = app(CurrentCompany::class)->get();

        $this->form = [
            'name' => $company->name,
            'motto' => $company->motto,
            'description' => $company->description,
            'industry' => $company->industry,
            'registration_number' => $company->registration_number,
            'tax_id' => $company->tax_id,
            'vat_number' => $company->vat_number,
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
        $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.motto' => ['nullable', 'string', 'max:160'],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.industry' => ['nullable', 'string', 'max:60'],
            'form.email' => ['nullable', 'email', 'max:120'],
            'form.website' => ['nullable', 'string', 'max:160'],
            'form.currency' => ['required', 'in:'.implode(',', config('opes.currencies'))],
            'form.country' => ['nullable', 'string', 'max:2'],
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
