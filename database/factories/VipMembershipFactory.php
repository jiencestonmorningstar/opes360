<?php

namespace Database\Factories;

use App\Models\VipMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VipMembership>
 */
class VipMembershipFactory extends Factory
{
    protected $model = VipMembership::class;

    public function definition(): array
    {
        return [
            'tier_name' => 'Gold',
            'discount_percent' => 15,
            'price_paid' => 50000,
            'currency' => 'XAF',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'status' => VipMembership::ACTIVE,
        ];
    }

    /**
     * A membership whose term has already run out, for exercising the paths
     * that must refuse a discount even before the nightly sweep has caught up
     * with the status column.
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_on' => now()->subYears(2)->toDateString(),
            'ends_on' => now()->subDay()->toDateString(),
        ]);
    }
}
