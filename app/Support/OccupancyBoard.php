<?php

namespace App\Support;

use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the letting business needs to see before the list of buildings.
 *
 * Three questions, in the order they cost money: which doors are earning
 * nothing (vacancy), which tenants are behind (arrears), and which leases run
 * out soon (endings). Nothing is stored — like Aging and ContractWatch this
 * is a photograph of a moment, and the durable record is what somebody does
 * about a row.
 *
 * The arithmetic is deliberately borrowed, not rebuilt: arrears come from the
 * existing Aging report (so the board and the aging screen can never
 * disagree), and endings come from ContractWatch (so a lease warns on the
 * same day as every other contract). This class only filters both down to the
 * rows the estate module owns.
 */
class OccupancyBoard
{
    public function __construct(protected ?CarbonInterface $asOf = null) {}

    /**
     * Vacant units, grouped under their property, worst first — the property
     * losing the most asking rent tops the list.
     *
     * @return Collection<int, array{property: Property, units: Collection<int, PropertyUnit>, target_rent_lost: float}>
     */
    public function vacancies(): Collection
    {
        return PropertyUnit::query()
            ->vacant()
            ->with('property')
            ->orderBy('label')
            ->get()
            ->filter(fn (PropertyUnit $unit) => $unit->property !== null)
            ->groupBy('property_id')
            ->map(fn (Collection $units) => [
                'property' => $units->first()->property,
                'units' => $units->values(),
                'target_rent_lost' => round($units->sum(fn (PropertyUnit $u) => (float) ($u->target_rent ?? 0)), 2),
            ])
            ->sortByDesc('target_rent_lost')
            ->values();
    }

    /**
     * Open tenancies whose tenant owes money, most owed first.
     *
     * The figure is Aging's own — `forParty()` on the tenant — so chasing a
     * tenant from this board and from the receivables screen is the same
     * conversation about the same number.
     *
     * @return Collection<int, array{tenancy: Tenancy, total: float, oldest_days: int, buckets: array<string, float>}>
     */
    public function arrears(): Collection
    {
        $aging = new Aging($this->asOf());

        return Tenancy::query()
            ->active()
            ->with(['tenant', 'unit.property'])
            ->get()
            ->filter(fn (Tenancy $t) => $t->tenant !== null)
            ->map(function (Tenancy $tenancy) use ($aging) {
                $row = $aging->forParty($tenancy->tenant);

                return [
                    'tenancy' => $tenancy,
                    'total' => $row['total'],
                    'oldest_days' => $row['oldest_days'],
                    'buckets' => $row['buckets'],
                ];
            })
            ->filter(fn (array $row) => $row['total'] > 0)
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Leases ending inside the window — ContractWatch's own list, filtered to
     * the contracts that are leases on open tenancies. Filtered, never
     * forked: if the watch's idea of "expiring" changes, this changes with
     * it.
     *
     * @return Collection<int, array{tenancy: Tenancy, contract: \App\Models\Contract}>
     */
    public function leasesEnding(int $days = 60): Collection
    {
        $tenancies = Tenancy::query()
            ->active()
            ->whereNotNull('contract_id')
            ->with(['tenant', 'unit.property'])
            ->get()
            ->keyBy('contract_id');

        if ($tenancies->isEmpty()) {
            return collect();
        }

        return (new ContractWatch($this->asOf))
            ->expiring($days)
            ->filter(fn ($contract) => $tenancies->has($contract->id))
            ->map(fn ($contract) => [
                'tenancy' => $tenancies[$contract->id],
                'contract' => $contract,
            ])
            ->values();
    }

    /**
     * The counts a landing screen's tiles show.
     *
     * @return array<string, int|float>
     */
    public function summary(int $endingWindow = 60): array
    {
        $vacancies = $this->vacancies();
        $arrears = $this->arrears();

        return [
            'vacant_units' => (int) $vacancies->sum(fn (array $row) => $row['units']->count()),
            'target_rent_lost' => round($vacancies->sum('target_rent_lost'), 2),
            'tenancies_in_arrears' => $arrears->count(),
            'arrears_total' => round($arrears->sum('total'), 2),
            'leases_ending' => $this->leasesEnding($endingWindow)->count(),
            'open_tenancies' => Tenancy::query()->active()->count(),
        ];
    }

    protected function asOf(): Carbon
    {
        return Carbon::parse($this->asOf ?? Carbon::now())->startOfDay();
    }
}
