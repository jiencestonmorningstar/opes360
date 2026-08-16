<?php

namespace App\Livewire\Estate;

use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\ServiceTicket;
use App\Models\Tenancy;
use App\Services\Estate\Tenancies;
use App\Support\Aging;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use RuntimeException;

/**
 * One building: its doors, who is behind each, and what has gone wrong.
 *
 * Every mutating act goes through the Tenancies service and shows the
 * service's own refusal when it says no — the screen never negotiates with
 * the rules, it repeats them.
 */
class Show extends Component
{
    public Property $property;

    // ── Adding a unit ───────────────────────────────────────────────────
    public bool $addingUnit = false;

    public string $unitLabel = '';

    public string $unitTargetRent = '';

    // ── Moving a tenant in ──────────────────────────────────────────────
    public ?string $lettingUnitId = null;

    public ?string $tenantId = null;

    public string $rent = '';

    public string $depositAmount = '';

    public string $movedInOn = '';

    public string $endsOn = '';

    public string $renewalType = 'none';

    public string $noticePeriodDays = '';

    // ── Moving a tenant out ─────────────────────────────────────────────
    public ?string $endingTenancyId = null;

    public string $retained = '';

    public string $retentionReason = '';

    public bool $force = false;

    public string $forceReason = '';

    // ── A maintenance request ───────────────────────────────────────────
    public ?string $maintenanceTenancyId = null;

    public string $maintenanceSubject = '';

    public string $maintenanceDescription = '';

    public function mount(Property $property): void
    {
        Gate::authorize('estate.view');

        $this->property = $property;
    }

    public function startAddingUnit(): void
    {
        Gate::authorize('estate.manage');

        $this->reset(['unitLabel', 'unitTargetRent']);
        $this->resetValidation();
        $this->addingUnit = true;
    }

    public function addUnit(): void
    {
        Gate::authorize('estate.manage');

        $this->validate([
            'unitLabel' => ['required', 'string', 'max:120'],
            'unitTargetRent' => ['nullable', 'numeric', 'min:0'],
        ], [
            'unitLabel.required' => 'What is the unit called — "Apartment 2B", "Shop 1"?',
        ]);

        if ($this->property->units()->where('label', $this->unitLabel)->exists()) {
            $this->addError('unitLabel', "There is already a unit called {$this->unitLabel} here.");

            return;
        }

        PropertyUnit::create([
            'company_id' => $this->property->company_id,
            'property_id' => $this->property->id,
            'label' => $this->unitLabel,
            'target_rent' => $this->unitTargetRent === '' ? null : (float) $this->unitTargetRent,
            'status' => 'vacant',
        ]);

        $this->addingUnit = false;
        $this->dispatch('toast', message: 'Unit added, vacant and ready to let.');
    }

    public function startLetting(string $unitId): void
    {
        Gate::authorize('estate.manage');

        $this->reset(['tenantId', 'rent', 'depositAmount', 'endsOn', 'noticePeriodDays']);
        $this->resetValidation();
        $this->renewalType = 'none';
        $this->movedInOn = now()->toDateString();

        $unit = $this->property->units()->findOrFail($unitId);
        $this->rent = $unit->target_rent !== null ? (string) (float) $unit->target_rent : '';
        $this->lettingUnitId = $unit->id;
    }

    public function let(): void
    {
        Gate::authorize('estate.manage');

        $this->validate([
            'tenantId' => ['required', 'string'],
            'rent' => ['required', 'numeric', 'min:1'],
            'depositAmount' => ['nullable', 'numeric', 'min:0'],
            'movedInOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date'],
            'renewalType' => ['required', 'in:none,auto,manual'],
            'noticePeriodDays' => ['nullable', 'integer', 'min:1', 'max:730'],
        ], [
            'tenantId.required' => 'Who is moving in?',
            'rent.required' => 'What is the rent?',
        ]);

        $unit = $this->property->units()->findOrFail($this->lettingUnitId);

        try {
            app(Tenancies::class)->start($unit, [
                'tenant_contact_id' => $this->tenantId,
                'rent' => (float) $this->rent,
                'deposit_amount' => $this->depositAmount === '' ? 0 : (float) $this->depositAmount,
                'moved_in_on' => $this->movedInOn,
                'ends_on' => $this->endsOn ?: null,
                'renewal_type' => $this->renewalType,
                'notice_period_days' => $this->noticePeriodDays === '' ? null : (int) $this->noticePeriodDays,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('letting', $e->getMessage());

            return;
        }

        $this->lettingUnitId = null;
        $this->dispatch('toast', message: 'Tenant moved in. The lease is on the register and the rent will bill itself.');
    }

    public function startEnding(string $tenancyId): void
    {
        Gate::authorize('estate.end-tenancy');

        $this->reset(['retained', 'retentionReason', 'forceReason']);
        $this->resetValidation();
        $this->force = false;
        $this->endingTenancyId = $tenancyId;
    }

    public function endTenancy(): void
    {
        Gate::authorize('estate.end-tenancy');

        $this->validate([
            'retained' => ['nullable', 'numeric', 'min:0'],
            'retentionReason' => ['nullable', 'string', 'max:500'],
            'forceReason' => ['nullable', 'string', 'max:500'],
        ]);

        $tenancy = Tenancy::query()->findOrFail($this->endingTenancyId);

        try {
            app(Tenancies::class)->endTenancy($tenancy, [
                'retained' => $this->retained === '' ? 0 : (float) $this->retained,
                'retention_reason' => $this->retentionReason ?: null,
                'force' => $this->force,
                'force_reason' => $this->forceReason ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('ending', $e->getMessage());

            return;
        }

        $this->endingTenancyId = null;
        $this->dispatch('toast', message: 'Tenancy ended and the deposit settled in the books.');
    }

    public function startMaintenance(string $tenancyId): void
    {
        Gate::authorize('estate.manage');

        $this->reset(['maintenanceSubject', 'maintenanceDescription']);
        $this->resetValidation();
        $this->maintenanceTenancyId = $tenancyId;
    }

    public function reportMaintenance(): void
    {
        Gate::authorize('estate.manage');

        $this->validate([
            'maintenanceSubject' => ['required', 'string', 'max:180'],
            'maintenanceDescription' => ['nullable', 'string', 'max:2000'],
        ], [
            'maintenanceSubject.required' => 'What needs fixing?',
        ]);

        $tenancy = Tenancy::query()->findOrFail($this->maintenanceTenancyId);

        try {
            app(Tenancies::class)->reportMaintenance($tenancy, [
                'subject' => $this->maintenanceSubject,
                'description' => $this->maintenanceDescription ?: null,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('maintenance', $e->getMessage());

            return;
        }

        $this->maintenanceTenancyId = null;
        $this->dispatch('toast', message: 'Logged at the service desk — it is on the board with a clock.');
    }

    public function render(): View
    {
        Gate::authorize('estate.view');

        $this->property->load(['landlord', 'units.currentTenancy.tenant', 'units.currentTenancy.lease']);

        $aging = new Aging;

        $arrears = $this->property->units
            ->map(fn (PropertyUnit $unit) => $unit->currentTenancy)
            ->filter()
            ->filter(fn (Tenancy $t) => $t->tenant !== null)
            ->mapWithKeys(fn (Tenancy $t) => [$t->id => $aging->forParty($t->tenant)['total']]);

        return view('livewire.estate.show', [
            'arrears' => $arrears,
            'tickets' => ServiceTicket::query()
                ->whereIn('property_unit_id', $this->property->units->pluck('id'))
                ->with('contact')
                ->latest('opened_at')
                ->limit(20)
                ->get(),
            'papers' => $this->property->papers(),
            'tenants' => Contact::query()->orderBy('company_name')->orderBy('name')->get(),
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
        ])->layout('components.layouts.app', ['title' => $this->property->name, 'active' => 'estate']);
    }
}
