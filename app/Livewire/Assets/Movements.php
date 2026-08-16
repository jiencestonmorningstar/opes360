<?php

namespace App\Livewire\Assets;

use App\Models\AssetLocation;
use App\Models\AssetMaintenance;
use App\Models\FixedAsset;
use App\Services\Assets\AssetMovements;
use App\Services\Assets\AssetServicing;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Where the business's things are, and when they were last looked after.
 *
 * The register next door answers what is owned and what it is worth. This
 * answers the questions asked when something is actually needed: who has the
 * laptop, which site is the generator at, and what is overdue a service.
 */
class Movements extends Component
{
    #[Url]
    public string $tab = 'assets'; // assets|servicing|sites

    // ── Transfer form ───────────────────────────────────────────────────
    public ?string $transferring = null;

    public ?string $toLocationId = null;

    public ?string $toCustodianId = null;

    public string $transferredOn = '';

    public string $reason = '';

    // ── Servicing form ──────────────────────────────────────────────────
    public bool $booking = false;

    public ?string $jobAssetId = null;

    public string $jobKind = 'service';

    public string $jobTitle = '';

    public string $jobDueOn = '';

    public string $jobInterval = '';

    // ── Site form ───────────────────────────────────────────────────────
    public bool $addingSite = false;

    public string $siteName = '';

    public string $siteCode = '';

    public string $siteAddress = '';

    public function startTransfer(string $assetId): void
    {
        Gate::authorize('assets.transfer');

        $asset = FixedAsset::findOrFail($assetId);

        $this->transferring = $asset->id;
        $this->toLocationId = $asset->asset_location_id;
        $this->toCustodianId = $asset->custodian_id === null ? null : (string) $asset->custodian_id;
        $this->transferredOn = now()->toDateString();
        $this->reason = '';
    }

    public function transfer(): void
    {
        Gate::authorize('assets.transfer');

        $this->validate([
            'transferredOn' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $asset = FixedAsset::findOrFail($this->transferring);

        try {
            app(AssetMovements::class)->transfer($asset, [
                'to_location_id' => $this->toLocationId ?: null,
                'to_custodian_id' => $this->toCustodianId === null || $this->toCustodianId === ''
                    ? null
                    : (int) $this->toCustodianId,
                'reason' => $this->reason ?: null,
            ], Carbon::parse($this->transferredOn), auth()->id());
        } catch (RuntimeException $e) {
            $this->addError('transferring', $e->getMessage());

            return;
        }

        $this->transferring = null;
        $this->dispatch('toast', message: "{$asset->name} has been transferred.");
    }

    public function bookServicing(): void
    {
        Gate::authorize('assets.maintain');

        $this->validate([
            'jobAssetId' => ['required', 'string'],
            'jobTitle' => ['required', 'string', 'max:160'],
            'jobDueOn' => ['required', 'date'],
            'jobInterval' => ['nullable', 'numeric', 'min:1', 'max:120'],
        ]);

        $asset = FixedAsset::findOrFail($this->jobAssetId);

        AssetMaintenance::create([
            'fixed_asset_id' => $asset->id,
            'kind' => $this->jobKind,
            'title' => $this->jobTitle,
            'due_on' => $this->jobDueOn,
            'interval_months' => $this->jobInterval === '' ? null : (int) $this->jobInterval,
            'created_by' => auth()->id(),
        ]);

        $this->reset('booking', 'jobTitle', 'jobDueOn', 'jobInterval');
        $this->dispatch('toast', message: 'Servicing booked.');
    }

    public function completeServicing(string $id): void
    {
        Gate::authorize('assets.maintain');

        try {
            app(AssetServicing::class)->complete(AssetMaintenance::findOrFail($id));
        } catch (RuntimeException $e) {
            $this->addError('servicing', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Marked as done.');
    }

    public function addSite(): void
    {
        Gate::authorize('assets.update');

        $this->validate([
            'siteName' => ['required', 'string', 'max:120'],
            'siteCode' => ['nullable', 'string', 'max:40'],
            'siteAddress' => ['nullable', 'string', 'max:500'],
        ]);

        AssetLocation::create([
            'name' => $this->siteName,
            'code' => $this->siteCode ?: null,
            'address' => $this->siteAddress ?: null,
            'is_active' => true,
        ]);

        $this->reset('addingSite', 'siteName', 'siteCode', 'siteAddress');
        $this->dispatch('toast', message: 'Site added.');
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        return view('livewire.assets.movements', [
            'assets' => FixedAsset::query()
                ->where('status', 'active')
                ->with(['locationRecord', 'custodian'])
                ->orderBy('name')
                ->get(),
            // Overdue first, because that is the whole reason to open this.
            'servicing' => AssetMaintenance::query()
                ->outstanding()
                ->with('asset')
                ->orderByRaw('due_on IS NULL, due_on')
                ->get(),
            'recentlyDone' => AssetMaintenance::query()
                ->whereNotNull('completed_on')
                ->with('asset')
                ->latest('completed_on')
                ->limit(10)
                ->get(),
            'sites' => AssetLocation::query()
                ->withCount('assets')
                ->orderBy('name')
                ->get(),
            // Only people who actually work here can be handed an asset.
            'people' => $company?->users()->orderBy('name')->get(['users.id', 'users.name'])
                ?? collect(),
            'kinds' => AssetMaintenance::KINDS,
        ])->layout('components.layouts.app', ['title' => 'Asset movements', 'active' => 'assets']);
    }
}
