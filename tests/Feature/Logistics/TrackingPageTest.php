<?php

namespace Tests\Feature\Logistics;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Illuminate\Support\Str;

class TrackingPageTest extends LogisticsTestCase
{
    public function test_the_tracking_page_shows_this_shipments_history_and_route(): void
    {
        $shipment = $this->shipment();
        $manifest = $this->manifest();
        $this->dispatcher()->load($shipment, $manifest, $this->owner);
        $this->dispatcher()->dispatch($manifest, $this->owner);

        $response = $this->get('/track/'.$shipment->tracking_token);

        $response->assertOk()
            ->assertSee($shipment->reference)
            ->assertSee('Douala')
            ->assertSee('Bafoussam')
            ->assertSee('In transit')
            ->assertSee('Booked');
    }

    public function test_the_tracking_page_keeps_the_business_private(): void
    {
        $shipment = $this->shipment(['declared_value' => 9_000_000]);

        $response = $this->get('/track/'.$shipment->tracking_token);

        // The money never renders: not the freight charge, not the declared
        // value, not the manifest, not the vehicle.
        $response->assertOk()
            ->assertDontSee('250,000')
            ->assertDontSee('250 000')
            ->assertDontSee('9,000,000')
            ->assertDontSee('Actros')
            ->assertDontSee('LT-234-AB');
    }

    public function test_the_tracking_page_shows_nothing_of_a_second_companys_cargo(): void
    {
        $mine = $this->shipment();

        // A second transporter with its own shipment.
        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'rival-'.Str::lower(Str::random(6)),
            'name' => 'Rival Transport',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);
        $other->forceFill(['modules' => ['logistics' => true]])->save();
        Modules::flush();

        $theirs = app(CurrentCompany::class)->as($other, function () use ($other, $otherOwner) {
            $sender = Contact::create(['company_id' => $other->id, 'name' => 'Chantier Kribi']);
            $receiver = Contact::create(['company_id' => $other->id, 'name' => 'Depot Ebolowa']);

            return $this->dispatcher()->book([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'cargo_description' => 'Secret machine parts',
                'from_location' => 'Kribi',
                'to_location' => 'Ebolowa',
            ], $otherOwner);
        });

        // My token renders my shipment and not a word of theirs.
        $this->get('/track/'.$mine->tracking_token)
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee('Secret machine parts')
            ->assertDontSee('Rival Transport')
            ->assertDontSee($theirs->reference);

        // Their token resolves THEIR tenant — never mine.
        $this->get('/track/'.$theirs->tracking_token)
            ->assertOk()
            ->assertSee($theirs->reference)
            ->assertSee('Rival Transport')
            ->assertDontSee($mine->reference)
            ->assertDontSee('40 sacks of cement');
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get('/track/not-a-real-token')->assertNotFound();
    }

    public function test_a_business_that_switched_logistics_off_takes_its_tracking_pages_down(): void
    {
        $shipment = $this->shipment();

        $this->company->forceFill(['modules' => ['logistics' => false]])->save();
        Modules::flush();

        $this->get('/track/'.$shipment->tracking_token)->assertNotFound();
    }
}
