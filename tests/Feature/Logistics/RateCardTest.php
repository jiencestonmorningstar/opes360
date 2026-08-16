<?php

namespace Tests\Feature\Logistics;

use App\Livewire\Logistics\Index;
use App\Models\Company;
use App\Models\FreightRate;
use App\Models\Shipment;
use App\Services\Logistics\RateCards;
use App\Support\CurrentCompany;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Rate cards: the card proposes, the person decides.
 */
class RateCardTest extends LogisticsTestCase
{
    protected function rates(): RateCards
    {
        return app(RateCards::class);
    }

    protected function card(float $perKg = 100, float $minimum = 50_000): FreightRate
    {
        return $this->rates()->put([
            'from_location' => 'Douala',
            'to_location' => 'Bafoussam',
            'per_kg' => $perKg,
            'minimum' => $minimum,
        ], $this->owner);
    }

    public function test_a_quote_is_per_kg_times_weight(): void
    {
        $this->card();

        $this->assertSame(200_000.0, $this->rates()->quote('Douala', 'Bafoussam', 2000));
    }

    public function test_the_minimum_is_the_floor(): void
    {
        $this->card();

        $this->assertSame(50_000.0, $this->rates()->quote('Douala', 'Bafoussam', 10));
        // No weight recorded: the smallest job still costs the floor.
        $this->assertSame(50_000.0, $this->rates()->quote('Douala', 'Bafoussam', null));
    }

    public function test_routes_match_case_insensitively_and_uncovered_routes_quote_nothing(): void
    {
        $this->card();

        $this->assertSame(200_000.0, $this->rates()->quote('douala', 'BAFOUSSAM', 2000));
        $this->assertNull($this->rates()->quote('Douala', 'Garoua', 2000));
    }

    public function test_saving_a_route_twice_edits_the_card(): void
    {
        $this->card(100, 50_000);
        $this->card(120, 60_000);

        $this->assertSame(1, FreightRate::query()->count());
        $this->assertSame(60_000.0, $this->rates()->quote('Douala', 'Bafoussam', null));
    }

    public function test_a_rate_card_never_crosses_companies(): void
    {
        $this->card();

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);
        $this->assertNull($this->rates()->quote('Douala', 'Bafoussam', 2000));
        app(CurrentCompany::class)->set($this->company);
    }

    public function test_the_booking_form_proposes_from_the_card_and_the_clerk_decides(): void
    {
        $this->card();

        // The card proposes...
        $component = Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->set('fromLocation', 'Douala')
            ->set('toLocation', 'Bafoussam')
            ->set('weightKg', '2000')
            ->assertSet('freightAmount', '200000');

        // ...a heavier load re-quotes over its own proposal...
        $component->set('weightKg', '3000')->assertSet('freightAmount', '300000');

        // ...but a figure the clerk typed stands, whatever the card thinks.
        $component
            ->set('freightAmount', '175000')
            ->set('weightKg', '2500')
            ->assertSet('freightAmount', '175000');
    }

    public function test_the_priced_booking_carries_the_agreed_amount(): void
    {
        $this->card();

        Livewire::actingAs($this->owner)
            ->test(Index::class)
            ->set('senderId', $this->sender->id)
            ->set('receiverId', $this->receiver->id)
            ->set('cargo', '40 sacks of cement')
            ->set('fromLocation', 'Douala')
            ->set('toLocation', 'Bafoussam')
            ->set('weightKg', '2000')
            ->call('book')
            ->assertHasNoErrors();

        $this->assertSame(
            200_000.0,
            (float) Shipment::query()->latest()->first()->freight_amount,
        );
    }
}
