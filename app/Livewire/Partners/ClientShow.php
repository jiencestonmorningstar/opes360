<?php

namespace App\Livewire\Partners;

use App\Livewire\Business\Stationery;
use App\Models\CardIssuance;
use App\Models\Company;
use App\Models\PartnerClient;
use App\Services\Partners\PartnerProgramme;
use App\Support\CardCatalog;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * One client, and the stationery the partner has printed for them.
 *
 * Issuing is two deliberate steps — choose a design, then confirm — because the
 * second step is what charges the partner. A print dialog that opens on its own
 * and a fee that lands without being agreed to is how a print shop ends up
 * disputing its statement.
 */
class ClientShow extends Component
{
    public PartnerClient $client;

    public string $design = 'classic';

    /** Letterheads have their own four designs; the card set does not apply. */
    public string $letterheadDesign = 'rule';

    public string $asset = 'card';

    /**
     * Which sector's designs the picker is showing.
     *
     * There are ninety-eight designs, and each tile is a live render of the
     * real print sheet in an iframe. Showing them all at once put ninety-eight
     * iframes on one page — fine on a desk, ruinous on a phone over 3G, which
     * is the connection this is actually used on.
     */
    public string $sector = 'universal';

    public string $holderName = '';

    public string $holderTitle = 'Proprietor';

    public bool $confirming = false;

    /** Set after issuing, so the page can offer the print sheet for it. */
    public ?string $issuedUrl = null;

    public function mount(PartnerClient $client): void
    {
        Gate::authorize('partners.view');

        $this->client = $client;
        $this->holderName = $client->contact_name ?: $client->name;
        $this->sector = CardCatalog::sectorFor($client->industry ?? '') ?? 'universal';
        $this->design = $this->sector === 'universal'
            ? 'classic'
            : $this->firstDesignForSector($client->industry ?? '');
    }

    public function setSector(string $sector): void
    {
        $this->sector = $sector === 'universal' || array_key_exists($sector, CardCatalog::bySector())
            ? $sector
            : 'universal';
    }

    /**
     * Open on something plausible for the client's trade rather than on the
     * first design in the list — a mechanic should not have to scroll past
     * ninety cards to find one that looks like a garage.
     */
    protected function firstDesignForSector(string $industry): string
    {
        $sector = CardCatalog::sectorFor($industry);

        foreach (CardCatalog::designs() as $key => $design) {
            if (($design['sector'] ?? null) === $sector) {
                return $key;
            }
        }

        return 'classic';
    }

    public function selectDesign(string $design): void
    {
        if (in_array($design, Company::cardDesigns(), true)) {
            $this->design = $design;
            $this->issuedUrl = null;
        }
    }

    public function selectLetterhead(string $design): void
    {
        if (array_key_exists($design, Stationery::LETTERHEAD_DESIGNS)) {
            $this->letterheadDesign = $design;
            $this->issuedUrl = null;
        }
    }

    /** Whichever design applies to the asset currently being printed. */
    public function activeDesign(): string
    {
        return $this->asset === 'letterhead' ? $this->letterheadDesign : $this->design;
    }

    public function startIssue(): void
    {
        Gate::authorize('partners.issue');

        $this->confirming = true;
    }

    public function cancel(): void
    {
        $this->confirming = false;
    }

    public function issue(PartnerProgramme $programme): void
    {
        Gate::authorize('partners.issue');

        $this->validate([
            'holderName' => ['required', 'string', 'max:120'],
            'holderTitle' => ['nullable', 'string', 'max:80'],
        ]);

        $partner = app(CurrentCompany::class)->get();
        abort_if($partner === null, 403);

        $programme->recordIssuance(
            partner: $partner,
            asset: $this->asset,
            design: $this->activeDesign(),
            subjectName: $this->client->name,
            client: $this->client,
            issuer: auth()->user(),
        );

        $this->confirming = false;
        $this->issuedUrl = route('partners.clients.print', [
            'client' => $this->client,
            'asset' => $this->asset,
            'design' => $this->activeDesign(),
            'name' => $this->holderName,
            'title' => $this->holderTitle,
            'print' => 1,
        ]);

        session()->flash('status', 'Card issued and charged. Open the print sheet to produce it.');
    }

    /**
     * Cancel the charge for a card that was printed wrong.
     *
     * The row stays and is marked void rather than being deleted: a partner
     * querying their statement needs to see that the charge existed and why it
     * was dropped, not find a hole where it used to be.
     */
    public function voidIssuance(string $id, string $reason = ''): void
    {
        Gate::authorize('partners.manage');

        $issuance = CardIssuance::query()
            ->where('partner_client_id', $this->client->id)
            ->find($id);

        if ($issuance === null || ! $issuance->isBilled()) {
            return;
        }

        $issuance->void($reason !== '' ? $reason : 'Voided by the partner');

        session()->flash('status', 'Charge cancelled.');
    }

    public function render(): View
    {
        $sectorDesigns = $this->sector === 'universal'
            ? array_fill_keys(Company::CARD_DESIGNS, null)
            : (CardCatalog::bySector()[$this->sector] ?? []);

        return view('livewire.partners.client-show', [
            'issuances' => CardIssuance::query()
                ->where('partner_client_id', $this->client->id)
                ->latest()->get(),
            'sectorDesigns' => array_keys($sectorDesigns),
            'sectors' => array_merge(['universal'], array_keys(CardCatalog::bySector())),
            'recommendedSector' => CardCatalog::sectorFor($this->client->industry ?? ''),
            'letterheadDesigns' => Stationery::LETTERHEAD_DESIGNS,
            'fee' => (int) config('opes.partners.card_fee'),
            'previewUrl' => route('partners.clients.print', [
                'client' => $this->client,
                'asset' => $this->asset,
                'design' => $this->activeDesign(),
                'name' => $this->holderName,
                'title' => $this->holderTitle,
                'preview' => 1,
            ]),
        ])->layout('components.layouts.app', [
            'title' => $this->client->name,
            'active' => 'partners',
        ]);
    }
}
