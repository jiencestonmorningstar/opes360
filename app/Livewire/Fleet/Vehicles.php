<?php

namespace App\Livewire\Fleet;

use App\Models\AssetMaintenance;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FuelLog;
use App\Models\VehicleDetail;
use App\Models\VehicleTrip;
use App\Services\Fleet\FleetAlerts;
use App\Services\Fleet\VehicleUsage;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The van side of the asset register.
 *
 * The register says what the business owns; movements say where it is and who
 * has it. This says how far it has gone, what it drank getting there, and
 * whether it is legal to be on the road at all — the three questions a van
 * raises that a desk or a generator never does.
 */
class Vehicles extends Component
{
    #[Url]
    public string $tab = 'fleet'; // fleet|trips|fuel|papers

    // ── Vehicle details form ────────────────────────────────────────────
    public ?string $editing = null;

    public string $registration = '';

    public string $vin = '';

    public string $make = '';

    public string $model = '';

    public string $year = '';

    public string $fuelType = '';

    public string $tankLitres = '';

    public string $insuranceExpiresOn = '';

    public string $roadworthyExpiresOn = '';

    public string $licenceExpiresOn = '';

    // ── Trip form ───────────────────────────────────────────────────────
    public ?string $tripAssetId = null;

    public ?string $tripDriverId = null;

    public string $tripDate = '';

    public string $tripStart = '';

    public string $tripEnd = '';

    public string $tripPurpose = '';

    // ── Fuel form ───────────────────────────────────────────────────────
    public ?string $fuelAssetId = null;

    public ?string $fuelDriverId = null;

    public string $fuelDate = '';

    public string $fuelLitres = '';

    public string $fuelOdometer = '';

    public ?string $fuelExpenseId = null;

    public bool $fuelFullTank = true;

    public function mount(): void
    {
        $this->tripDate = now()->toDateString();
        $this->fuelDate = now()->toDateString();
    }

    /** Load a vehicle's papers into the form, or start a blank set for an asset. */
    public function startVehicle(string $assetId): void
    {
        Gate::authorize('assets.update');

        $asset = FixedAsset::findOrFail($assetId);
        $vehicle = $asset->vehicle;

        $this->editing = $asset->id;
        $this->registration = (string) ($vehicle?->registration ?? '');
        $this->vin = (string) ($vehicle?->vin ?? '');
        $this->make = (string) ($vehicle?->make ?? '');
        $this->model = (string) ($vehicle?->model ?? '');
        $this->year = (string) ($vehicle?->year ?? '');
        $this->fuelType = (string) ($vehicle?->fuel_type ?? '');
        $this->tankLitres = (string) ($vehicle?->tank_litres ?? '');
        $this->insuranceExpiresOn = $vehicle?->insurance_expires_on?->toDateString() ?? '';
        $this->roadworthyExpiresOn = $vehicle?->roadworthy_expires_on?->toDateString() ?? '';
        $this->licenceExpiresOn = $vehicle?->licence_expires_on?->toDateString() ?? '';
    }

    public function saveVehicle(): void
    {
        Gate::authorize('assets.update');

        $this->validate([
            'registration' => ['nullable', 'string', 'max:40'],
            'vin' => ['nullable', 'string', 'max:40'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'numeric', 'min:1900', 'max:2200'],
            'tankLitres' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'insuranceExpiresOn' => ['nullable', 'date'],
            'roadworthyExpiresOn' => ['nullable', 'date'],
            'licenceExpiresOn' => ['nullable', 'date'],
        ]);

        $asset = FixedAsset::findOrFail($this->editing);

        VehicleDetail::updateOrCreate(
            ['fixed_asset_id' => $asset->id],
            [
                'registration' => $this->registration ?: null,
                'vin' => $this->vin ?: null,
                'make' => $this->make ?: null,
                'model' => $this->model ?: null,
                'year' => $this->year === '' ? null : (int) $this->year,
                'fuel_type' => $this->fuelType ?: null,
                'tank_litres' => $this->tankLitres === '' ? null : $this->tankLitres,
                'insurance_expires_on' => $this->insuranceExpiresOn ?: null,
                'roadworthy_expires_on' => $this->roadworthyExpiresOn ?: null,
                'licence_expires_on' => $this->licenceExpiresOn ?: null,
            ],
        );

        $this->editing = null;
        $this->dispatch('toast', message: "{$asset->name} updated.");
    }

    /**
     * Logging a trip is the custodial job, not the accounting one — the same
     * trust that already lets somebody sign a van out to a driver.
     */
    public function logTrip(): void
    {
        Gate::authorize('assets.transfer');

        $this->validate([
            'tripAssetId' => ['required', 'string'],
            'tripDate' => ['required', 'date'],
            'tripStart' => ['required', 'numeric', 'min:0'],
            'tripEnd' => ['required', 'numeric', 'min:0'],
            'tripPurpose' => ['nullable', 'string', 'max:160'],
        ]);

        $asset = FixedAsset::findOrFail($this->tripAssetId);

        try {
            app(VehicleUsage::class)->logTrip($asset, [
                'driver_id' => $this->tripDriverId ? (int) $this->tripDriverId : null,
                'trip_date' => $this->tripDate,
                'start_odometer' => (int) $this->tripStart,
                'end_odometer' => (int) $this->tripEnd,
                'purpose' => $this->tripPurpose ?: null,
            ], auth()->id());
        } catch (RuntimeException $e) {
            // Against the end reading, because that is the field somebody has
            // to go back and look at.
            $this->addError('tripEnd', $e->getMessage());

            return;
        }

        $this->reset('tripStart', 'tripEnd', 'tripPurpose');
        $this->dispatch('toast', message: 'Trip logged.');
    }

    public function logFuel(): void
    {
        Gate::authorize('assets.transfer');

        $this->validate([
            'fuelAssetId' => ['required', 'string'],
            'fuelDate' => ['required', 'date'],
            'fuelLitres' => ['required', 'numeric', 'min:0.01'],
            'fuelOdometer' => ['nullable', 'numeric', 'min:0'],
        ]);

        $asset = FixedAsset::findOrFail($this->fuelAssetId);
        $expense = $this->fuelExpenseId ? Expense::find($this->fuelExpenseId) : null;

        try {
            app(VehicleUsage::class)->logFuel($asset, [
                'driver_id' => $this->fuelDriverId ? (int) $this->fuelDriverId : null,
                'filled_on' => $this->fuelDate,
                'litres' => $this->fuelLitres,
                'odometer' => $this->fuelOdometer === '' ? null : (int) $this->fuelOdometer,
                'is_full_tank' => $this->fuelFullTank,
            ], $expense, auth()->id());
        } catch (RuntimeException $e) {
            $this->addError('fuelLitres', $e->getMessage());

            return;
        }

        $this->reset('fuelLitres', 'fuelOdometer', 'fuelExpenseId');
        $this->dispatch('toast', message: 'Fill recorded.');
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();
        $usage = app(VehicleUsage::class);
        $alerts = app(FleetAlerts::class);

        // Vehicles are assets that have been given vehicle details. The
        // category is not the test: a business may file a motorbike anywhere
        // and still want a milometer against it.
        $vehicles = FixedAsset::query()
            ->whereHas('vehicle')
            ->where('status', 'active')
            ->with('vehicle', 'custodian', 'locationRecord')
            ->orderBy('name')
            ->get();

        return view('livewire.fleet.vehicles', [
            'vehicles' => $vehicles,
            'odometers' => $vehicles->mapWithKeys(fn ($v) => [$v->id => $usage->odometer($v)]),
            'consumption' => $vehicles->mapWithKeys(fn ($v) => [$v->id => $usage->consumptionPer100Km($v)]),
            // Assets that could become vehicles: anything active without
            // details yet. Adding a plate is what puts it in the fleet.
            'candidates' => FixedAsset::query()
                ->whereDoesntHave('vehicle')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(),
            'trips' => VehicleTrip::query()
                ->with('asset', 'driver')
                ->latest('trip_date')
                ->limit(50)
                ->get(),
            'fills' => FuelLog::query()
                ->with('asset', 'driver', 'expense')
                ->latest('filled_on')
                ->limit(50)
                ->get(),
            'papers' => $alerts->expiringPapers(60),
            'servicingDue' => $alerts->servicingDue(),
            'people' => $company?->users()->orderBy('name')->get(['users.id', 'users.name']) ?? collect(),
            'expenses' => Expense::query()
                ->whereDoesntHave('payments')
                ->latest('issue_date')
                ->limit(40)
                ->get(['id', 'reference', 'description', 'total']),
            'fuelTypes' => VehicleDetail::FUEL_TYPES,
            'kinds' => AssetMaintenance::KINDS,
        ])->layout('components.layouts.app', ['title' => 'Fleet', 'active' => 'assets']);
    }
}
