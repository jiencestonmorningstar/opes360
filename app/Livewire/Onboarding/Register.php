<?php

namespace App\Livewire\Onboarding;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationToken;
use App\Notifications\WelcomeNotification;
use App\Services\Partners\PartnerProgramme;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
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

    /*
     * XAF, not USD. Every price on the site is quoted in FCFA, mobile money
     * settles in it, and the TVA rules the documents follow are Cameroonian —
     * a business that accepted the default was issuing invoices in a currency
     * it does not trade in.
     */
    public string $currency = 'XAF';

    public string $country = '';

    public string $phone = '';

    /**
     * Referral and account kind, both carried in the URL.
     *
     * `ref` is a partner's code or a client's personal invite token; `type` is
     * how someone arrives from the partner programme page wanting a secretariat
     * account rather than a plain business. Neither is trusted: an unknown ref
     * simply produces an unattributed signup, and any type other than
     * 'secretariat' falls back to a normal business.
     */
    #[Url]
    public string $ref = '';

    #[Url]
    public string $type = '';

    /**
     * Resolved once on mount, so the form can say who invited you.
     *
     * A string rather than the Company itself: Livewire round-trips public
     * properties through the browser, and the only thing this needs on the far
     * side is a name to show.
     */
    public string $referrerName = '';

    public function mount(): void
    {
        $this->referrerName = app(PartnerProgramme::class)
            ->resolveReferral($this->ref)['partner']->name ?? '';
    }

    public function isSecretariatSignup(): bool
    {
        return $this->type === 'secretariat';
    }

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

        [$user, $company] = DB::transaction(function () {
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
                'kind' => $this->isSecretariatSignup() ? 'secretariat' : 'business',
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

            return [$user, $company];
        });

        // Attribution is outside the transaction and deliberately cannot fail
        // the registration: a mistyped or expired ref must leave someone with
        // an account, not an error page. Written once — see PartnerProgramme.
        if ($this->ref !== '') {
            app(PartnerProgramme::class)->attribute($company, $this->ref);
        }

        // Outside the transaction: a queued welcome message must never be the
        // reason a registration rolls back, and it has nothing to add to one.
        $user->notify(new WelcomeNotification($company));

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
