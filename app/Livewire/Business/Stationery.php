<?php

namespace App\Livewire\Business;

use App\Models\Company;
use App\Models\VerificationToken;
use App\Support\CardCatalog;
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

    public string $letterheadDesign = 'rule';

    public string $cardName = '';

    public string $cardTitle = '';

    public string $cardDesign = 'classic';

    /** Which card-design category is open: 'universal' or a catalogue sector. */
    public string $cardSector = 'universal';

    public string $stampShape = 'circular';

    public const ASSETS = [
        'letterhead' => 'Letterhead',
        'card' => 'Business Card',
        'signature' => 'Email Signature',
        'stamp' => 'Company Stamp',
    ];

    /** The letterhead designs a business can print on. Null column = 'rule'. */
    public const LETTERHEAD_DESIGNS = [
        'rule' => 'Rule',
        'banner' => 'Banner',
        'sidebar' => 'Sidebar',
        'crest' => 'Crest',
    ];

    /** The same set as a flat list, for anything validating a request value. */
    public const LETTERHEAD_DESIGNS_KEYS = ['rule', 'banner', 'sidebar', 'crest'];

    public function mount(): void
    {
        if (! array_key_exists($this->asset, self::ASSETS)) {
            $this->asset = 'letterhead';
        }

        $this->cardName = auth()->user()->name;
        $this->cardTitle = 'Business Owner';

        $company = app(CurrentCompany::class)->get();
        $this->cardDesign = $company?->cardDesign() ?? 'classic';

        // Open on the category of the saved design; a company whose industry
        // matches a catalogue sector starts there instead — the recommendation.
        $saved = CardCatalog::design($this->cardDesign);
        $this->cardSector = $saved['sector']
            ?? CardCatalog::sectorFor($company?->industry)
            ?? 'universal';

        $design = app(CurrentCompany::class)->get()?->letterhead_design;
        $this->letterheadDesign = array_key_exists((string) $design, self::LETTERHEAD_DESIGNS) ? $design : 'rule';
    }

    /**
     * Saves immediately rather than behind a button: the choice applies to the
     * printed sheet and every generated document, so the preview, the print
     * route and the record must never disagree.
     */
    public function setLetterheadDesign(string $design): void
    {
        if (! array_key_exists($design, self::LETTERHEAD_DESIGNS)) {
            return;
        }

        $this->authorize('business.manage-stationery');

        app(CurrentCompany::class)->get()->forceFill(['letterhead_design' => $design])->save();
        $this->letterheadDesign = $design;
    }

    public function setAsset(string $asset): void
    {
        $this->asset = array_key_exists($asset, self::ASSETS) ? $asset : 'letterhead';
    }

    public function setCardSector(string $sector): void
    {
        $this->cardSector = $sector === 'universal' || array_key_exists($sector, CardCatalog::bySector())
            ? $sector
            : 'universal';
    }

    /**
     * Persisted on the company rather than kept as screen state: the print
     * route reads the design from the record, so a saved print link keeps
     * producing whatever the business currently has chosen.
     */
    public function setCardDesign(string $design): void
    {
        if (! in_array($design, Company::cardDesigns(), true)) {
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
