<?php

namespace App\Livewire\Onboarding;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationToken;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Signup and first-run onboarding in one flow: account, then business identity.
 * Two steps because asking for eleven company fields before an account exists is
 * how you lose people on a phone.
 */
class Register extends Component
{
    public int $step = 1;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $businessName = '';

    public string $industry = '';

    public string $motto = '';

    public string $currency = 'USD';

    public string $country = '';

    public string $phone = '';

    public function continueToBusiness(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'same:passwordConfirmation'],
        ], [
            'password.same' => 'The two passwords do not match.',
            'email.unique' => 'An account already exists for that email.',
        ]);

        $this->step = 2;
    }

    public function back(): void
    {
        $this->step = 1;
    }

    public function finish(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'same:passwordConfirmation'],
            'businessName' => ['required', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:60'],
            'currency' => ['required', 'in:'.implode(',', config('opes.currencies'))],
            'country' => ['nullable', 'string', 'max:2'],
        ], [
            'businessName.required' => 'Your business needs a name.',
        ]);

        $user = DB::transaction(function () {
            $user = User::create([
                'name' => trim($this->name),
                'email' => strtolower(trim($this->email)),
                'password' => $this->password,
                'phone' => $this->phone ?: null,
            ]);

            // Set outside the fillable set on purpose: verification status must
            // never be assignable from request input. Signup marks the address
            // verified until the email-verification flow lands.
            $user->forceFill(['email_verified_at' => now()])->save();

            $company = Company::create([
                'slug' => $this->uniqueSlug($this->businessName),
                'name' => trim($this->businessName),
                'motto' => $this->motto ?: null,
                'industry' => $this->industry ?: null,
                'country' => $this->country ? strtoupper($this->country) : null,
                'currency' => $this->currency,
                'phones' => array_values(array_filter([$this->phone])),
                'email' => strtolower(trim($this->email)),
                'owner_id' => $user->id,
            ]);

            $company->users()->attach($user->id, [
                'role_id' => Role::where('slug', Role::OWNER)->value('id'),
                'job_title' => 'Business Owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            // The books start the day the business does. Seeded here rather
            // than lazily so an accountant opening the chart on day one finds
            // something to work from, and so the first invoice has somewhere
            // to post to.
            ChartOfAccounts::seed($company);

            $user->forceFill(['current_company_id' => $company->id])->save();

            // The business gets its permanent public identity immediately, so its
            // QR works from the first minute rather than on first visit to Business.
            app(CurrentCompany::class)->as($company, fn () => VerificationToken::create([
                'token' => VerificationToken::newToken(),
                'subject_type' => Company::class,
                'subject_id' => $company->id,
            ]));

            return $user;
        });

        Auth::login($user, remember: true);
        session()->regenerate();

        $this->redirectRoute('dashboard');
    }

    /** Slugs are public URLs, so collisions get a short suffix rather than failing. */
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
        return view('livewire.onboarding.register')
            ->layout('components.layouts.guest', ['title' => 'Create your business']);
    }
}
