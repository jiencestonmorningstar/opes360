<?php

namespace App\Livewire\Business;

use App\Models\Company;
use App\Models\VerificationToken;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Module 2 — Business Stationery.
 *
 * Assets are composed from the company's own record and rendered live, so a
 * change to the business profile is reflected everywhere immediately. Print
 * output goes through the same browser-print pipeline as documents.
 */
class Stationery extends Component
{
    use AuthorizesRequests;

    #[Url]
    public string $asset = 'letterhead';

    public string $size = 'a4';

    public string $cardName = '';

    public string $cardTitle = '';

    public string $cardDesign = 'classic';

    public string $stampShape = 'circular';

    public const ASSETS = [
        'letterhead' => 'Letterhead',
        'card' => 'Business Card',
        'signature' => 'Email Signature',
        'stamp' => 'Company Stamp',
    ];

    public function mount(): void
    {
        if (! array_key_exists($this->asset, self::ASSETS)) {
            $this->asset = 'letterhead';
        }

        $this->cardName = auth()->user()->name;
        $this->cardTitle = 'Business Owner';
        $this->cardDesign = app(CurrentCompany::class)->get()?->cardDesign() ?? 'classic';
    }

    public function setAsset(string $asset): void
    {
        $this->asset = array_key_exists($asset, self::ASSETS) ? $asset : 'letterhead';
    }

    /**
     * Persisted on the company rather than kept as screen state: the print
     * route reads the design from the record, so a saved print link keeps
     * producing whatever the business currently has chosen.
     */
    public function setCardDesign(string $design): void
    {
        if (! in_array($design, Company::CARD_DESIGNS, true)) {
            return;
        }

        $this->authorize('business.manage-stationery');

        $this->cardDesign = $design;
        app(CurrentCompany::class)->get()->update(['card_design' => $design]);
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        return view('livewire.business.stationery', [
            'company' => $company,
            'token' => $this->token($company),
        ])->layout('components.layouts.app', ['title' => 'Stationery', 'active' => 'business']);
    }

    protected function token(Company $company): VerificationToken
    {
        return VerificationToken::firstOrCreate(
            ['subject_type' => Company::class, 'subject_id' => $company->id],
            ['token' => VerificationToken::newToken(), 'company_id' => $company->id],
        );
    }
}
