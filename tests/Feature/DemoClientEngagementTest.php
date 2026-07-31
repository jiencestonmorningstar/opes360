<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\FormResponseReceivedNotification;
use App\Notifications\TicketSoldNotification;
use App\Services\TicketSeller;
use App\Support\CurrentCompany;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Jude Nshome (OPESWARE llc., opesware.com) is a real named client of the
 * demo company across the engagement modules: a form response, a purchased
 * ticket, and a published review, all queryable rows tied to his real name,
 * email and WhatsApp number — not just referenced in a description.
 *
 * Two things are proven here: that the seeder writes those rows correctly
 * (seedForms/seedEvent/seedReviews), and that the real public HTTP paths
 * (/f/{token}, /e/{token}) accept his data end to end, independent of the
 * seeder.
 */
class DemoClientEngagementTest extends TestCase
{
    use RefreshDatabase;

    protected const NAME = 'Jude Nshome';

    protected const EMAIL = 'nshomejude@gmail.com';

    protected const PHONE = '+237670416238';

    /* -----------------------------------------------------------------
     * The seeded demo company: Jude's rows must already be there, saved.
     * --------------------------------------------------------------- */

    protected function seedDemoCompany(): Company
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoCompanySeeder::class);

        return Company::where('slug', 'opesware-technologies')->firstOrFail();
    }

    public function test_jude_has_a_saved_form_response_on_the_seeded_workshop_form(): void
    {
        $company = $this->seedDemoCompany();

        app(CurrentCompany::class)->set($company);

        $form = Form::where('title', 'Client Workshop Registration')->firstOrFail();

        $response = $form->responses()
            ->whereJsonContains('answers->demo-name', self::NAME)
            ->first();

        $this->assertNotNull($response, 'Jude Nshome should have a real FormResponse row on the workshop form.');
        $this->assertSame(self::NAME, $response->answers['demo-name']);
        $this->assertSame(self::EMAIL, $response->answers['demo-email']);
        $this->assertSame($company->id, $response->company_id);

        // The answer is genuinely his, not a placeholder — it references his
        // real company and city.
        $this->assertStringContainsString('OPESWARE', $response->answers['demo-notes']);
        $this->assertStringContainsString('Douala', $response->answers['demo-notes']);
    }

    public function test_jude_has_a_real_issued_ticket_with_a_working_verification_page(): void
    {
        $company = $this->seedDemoCompany();

        app(CurrentCompany::class)->set($company);

        $ticket = Ticket::where('buyer_email', self::EMAIL)->firstOrFail();

        $this->assertSame(self::NAME, $ticket->buyer_name);
        $this->assertSame(self::PHONE, $ticket->buyer_phone);
        $this->assertSame($company->id, $ticket->company_id);
        // Issued and paid, but not yet checked in — he hasn't arrived at the
        // door in this snapshot of the demo data.
        $this->assertSame('issued', $ticket->status);
        $this->assertNotNull($ticket->paid_at);

        $token = $ticket->verificationToken;
        $this->assertNotNull($token, 'The ticket must carry a real verification token.');

        $this->get('/v/'.$token->token)
            ->assertOk()
            ->assertSee('Jude Nshome')
            ->assertSee($ticket->serial);
    }

    public function test_jude_has_a_published_review_that_appears_on_the_public_profile_with_correct_average(): void
    {
        $company = $this->seedDemoCompany();

        app(CurrentCompany::class)->set($company);

        $review = CompanyReview::where('author_name', self::NAME)->firstOrFail();

        $this->assertTrue($review->is_published);
        $this->assertSame(5, $review->rating);
        $this->assertStringContainsString('OPESWARE', $review->body);

        $published = CompanyReview::published()->get();
        $expectedAverage = round($published->avg('rating'), 1);

        $page = $this->get('/business/'.$company->slug);
        $page->assertOk()->assertSee('Jude Nshome');

        // The average shown on the page must match the same maths run
        // against the published rows directly (one decimal place).
        $page->assertSee(number_format($expectedAverage, 1).' / 5', false);
    }

    /* -----------------------------------------------------------------
     * The real public HTTP paths, exercised as Jude himself would use
     * them — not just seeder-created rows.
     * --------------------------------------------------------------- */

    public function test_jude_can_submit_the_real_public_form_endpoint(): void
    {
        Notification::fake();

        $owner = User::factory()->create();

        $company = Company::create([
            'slug' => 'opesware-live',
            'name' => 'Opesware Technologies',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($company, $owner);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $form = Form::create([
            'title' => 'Client Workshop Registration',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => [
                ['id' => 'name', 'type' => 'short_text', 'label' => 'Full name', 'required' => true, 'options' => []],
                ['id' => 'email', 'type' => 'email', 'label' => 'Email address', 'required' => true, 'options' => []],
                ['id' => 'company_email', 'type' => 'email', 'label' => 'Company email', 'required' => false, 'options' => []],
                ['id' => 'phone', 'type' => 'phone', 'label' => 'WhatsApp number', 'required' => true, 'options' => []],
                ['id' => 'address', 'type' => 'long_text', 'label' => 'Address', 'required' => false, 'options' => []],
            ],
        ]);

        // Posted through the real public endpoint, exactly as a browser at
        // the share link would — no seeder shortcut.
        $this->post('/f/'.$form->share_token, [
            'answers' => [
                'name' => self::NAME,
                'email' => self::EMAIL,
                'company_email' => 'nshomejude@opesware.com',
                'phone' => self::PHONE,
                'address' => 'Rue Tokoto, Bonapriso, Douala, Cameroon',
            ],
        ])->assertRedirect('/f/'.$form->share_token.'/thanks');

        $response = FormResponse::where('form_id', $form->id)->sole();

        $this->assertSame(self::NAME, $response->answers['name']);
        $this->assertSame(self::EMAIL, $response->answers['email']);
        // A .com company email, distinct from his personal gmail address.
        $this->assertSame('nshomejude@opesware.com', $response->answers['company_email']);
        // His WhatsApp number in international format, unmangled by validation.
        $this->assertSame(self::PHONE, $response->answers['phone']);
        $this->assertSame('Rue Tokoto, Bonapriso, Douala, Cameroon', $response->answers['address']);

        Notification::assertSentTo($owner, FormResponseReceivedNotification::class);
    }

    public function test_jude_can_purchase_through_the_real_public_event_endpoint(): void
    {
        Notification::fake();

        $owner = User::factory()->create();

        $company = Company::create([
            'slug' => 'opesware-live-events',
            'name' => 'Opesware Technologies',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($company, $owner);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $event = Event::create([
            'title' => 'Product Showcase Evening',
            'venue' => 'Landmark Centre, Victoria Island',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $vip = $event->ticketTypes()->create([
            'company_id' => $company->id,
            'name' => 'VIP (front row + meet the team)',
            'price' => 75,
            'quantity' => 20,
        ]);

        // Posted through the real public purchase endpoint.
        $this->post('/e/'.$event->share_token, [
            'buyer_name' => self::NAME,
            'buyer_email' => self::EMAIL,
            'buyer_phone' => self::PHONE,
            'quantities' => [$vip->id => 1],
        ])->assertRedirect('/e/'.$event->share_token.'/tickets');

        $ticket = Ticket::where('buyer_email', self::EMAIL)->where('event_id', $event->id)->sole();

        $this->assertSame(self::NAME, $ticket->buyer_name);
        $this->assertSame(self::PHONE, $ticket->buyer_phone);
        $this->assertSame('issued', $ticket->status);
        $this->assertSame($vip->id, $ticket->ticket_type_id);

        // The confirmation page (reached via session) shows the real ticket.
        $this->get('/e/'.$event->share_token.'/tickets')
            ->assertOk()
            ->assertSee(self::NAME)
            ->assertSee($ticket->serial);

        // The ticket's own verification page also works.
        $token = $ticket->verificationToken;
        $this->get('/v/'.$token->token)->assertOk()->assertSee(self::NAME);

        Notification::assertSentTo($owner, TicketSoldNotification::class);
    }

    public function test_jude_can_check_in_at_the_door_via_the_real_verification_page(): void
    {
        $owner = User::factory()->create();

        $company = Company::create([
            'slug' => 'opesware-checkin',
            'name' => 'Opesware Technologies',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($company, $owner);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $event = Event::create([
            'title' => 'Product Showcase Evening',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $type = $event->ticketTypes()->create([
            'company_id' => $company->id,
            'name' => 'General',
            'price' => 25,
            'quantity' => 10,
        ]);

        $ticket = app(TicketSeller::class)->sell($event, [$type->id => 1], self::NAME, self::EMAIL, self::PHONE)[0];

        $this->assertFalse($ticket->isCheckedIn());

        $this->actingAs($owner)
            ->post('/tickets/'.$ticket->id.'/check-in')
            ->assertRedirect();

        $ticket->refresh();
        $this->assertTrue($ticket->isCheckedIn());
        $this->assertSame($owner->id, $ticket->checked_in_by);

        // The verification page now shows the "already checked in" state.
        $token = $ticket->verificationToken;
        $this->get('/v/'.$token->token)
            ->assertOk()
            ->assertSee('Already checked in');
    }
}
