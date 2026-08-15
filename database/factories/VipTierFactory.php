<?php

namespace Database\Factories;

use App\Models\VipTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VipTier>
 */
class VipTierFactory extends Factory
{
    protected $model = VipTier::class;

    public function definition(): array
    {
        return [
            'name' => 'Gold',
            'price' => 50000,
            'currency' => 'XAF',
            'period_months' => 12,
            'discount_percent' => 15,
            'perks' => 'Free breakfast, late checkout',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
