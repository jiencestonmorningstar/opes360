<?php

namespace App\Livewire\Vip;

use App\Models\VipMembership;
use App\Models\VipTier;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * The tiers a business offers.
 *
 * A tier is withdrawn rather than deleted. Memberships sold from it carry their
 * own copy of the terms, so removing the row would not change what anybody was
 * sold — but it would erase the answer to "what was Gold, back then", and the
 * list of what is currently on offer is a different question from the list of
 * what has ever existed.
 */
class Tiers extends Component
{
    use AuthorizesRequests;

    public ?string $editing = null;

    public string $name = '';

    public string $price = '';

    public string $periodMonths = '12';

    public string $discountPercent = '';

    public string $perks = '';

    public function edit(string $id): void
    {
        Gate::authorize('vip.manage');

        $tier = VipTier::findOrFail($id);

        $this->editing = $tier->id;
        $this->name = $tier->name;
        $this->price = (string) (float) $tier->price;
        $this->periodMonths = (string) $tier->period_months;
        $this->discountPercent = (string) (float) $tier->discount_percent;
        $this->perks = (string) $tier->perks;
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        Gate::authorize('vip.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'periodMonths' => ['required', 'integer', 'min:1', 'max:120'],
            'discountPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'perks' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'Give the tier a name your customers will recognise.',
            'price.required' => 'Say what the tier costs to join.',
            'discountPercent.required' => 'Say what the member saves.',
            'discountPercent.max' => 'A discount cannot be more than the whole bill.',
            'periodMonths.max' => 'Ten years is the longest term this supports.',
        ]);

        $attributes = [
            'name' => trim($data['name']),
            'price' => $data['price'],
            'period_months' => $data['periodMonths'],
            'discount_percent' => $data['discountPercent'],
            'perks' => $data['perks'] !== '' ? $data['perks'] : null,
        ];

        if ($this->editing) {
            /*
             * Editing changes what FUTURE sales get. Memberships already sold
             * keep the terms they were sold under — they hold their own copy,
             * which is the whole reason those columns are duplicated.
             */
            VipTier::findOrFail($this->editing)->update($attributes);
            session()->flash('status', 'Tier updated. Members already signed up keep what they bought.');
        } else {
            VipTier::create($attributes + [
                'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
                'is_active' => true,
            ]);
            session()->flash('status', 'Tier added.');
        }

        $this->resetForm();
    }

    public function withdraw(string $id): void
    {
        Gate::authorize('vip.manage');

        VipTier::findOrFail($id)->update(['is_active' => false]);

        session()->flash('status', 'Tier withdrawn. It can no longer be sold; existing members are unaffected.');
    }

    public function restore(string $id): void
    {
        Gate::authorize('vip.manage');

        VipTier::findOrFail($id)->update(['is_active' => true]);

        session()->flash('status', 'Tier back on sale.');
    }

    public function render(): View
    {
        $this->authorize('viewAny', VipMembership::class);

        return view('livewire.vip.tiers', [
            'tiers' => VipTier::withCount('memberships')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ])->layout('components.layouts.app', ['title' => 'VIP tiers', 'active' => 'vip']);
    }

    protected function resetForm(): void
    {
        $this->reset(['editing', 'name', 'price', 'discountPercent', 'perks']);
        $this->periodMonths = '12';
        $this->resetValidation();
    }
}
