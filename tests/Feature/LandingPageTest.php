<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ContactMessageNotification;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The public marketing site: home, about, features, pricing and contact.
 * '/' is the one URI shared with the authenticated app — guest and auth
 * middleware never both match, so each visitor gets the right page.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_marketing_home_at_the_root(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Opes360', false)
            ->assertSee('3,000', false);
    }

    /** Four slides of three cards apiece, to the right of the hero text. */
    public function test_the_home_page_shows_a_four_slide_feature_slider(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertSee('Opes Forms', false)
            ->assertSee('Opes Events', false)
            ->assertSee('Loyalty Program', false)
            ->assertSee('Public Reviews', false)
            ->assertSee('Offline-first', false)
            ->assertSee('aria-label="Go to slide 4"', false);
    }

    public function test_authenticated_users_still_see_the_dashboard_at_the_root(): void
    {
        $this->travelTo(Carbon::parse('2026-07-27 09:30:00', 'UTC'));

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoCompanySeeder::class);

        $user = User::where('email', 'john@opesware.com')->firstOrFail();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Good morning');
    }

    public function test_the_about_page_renders(): void
    {
        $this->get(route('marketing.about'))
            ->assertOk()
            ->assertSee('Opesware Technologies');
    }

    public function test_the_features_page_renders(): void
    {
        $this->get(route('marketing.features'))
            ->assertOk()
            ->assertSee('Sales &amp; Invoicing', false)
            ->assertSee('QR Verification', false);
    }

    public function test_the_pricing_page_shows_all_tiers_and_figures(): void
    {
        $response = $this->get(route('marketing.pricing'))->assertOk();

        $response->assertSee('Basic', false)
            ->assertSee('Growth', false)
            ->assertSee('Business', false)
            ->assertSee('3,000', false)
            ->assertSee('30,000', false)
            ->assertSee('9,000', false)
            ->assertSee('90,000', false)
            ->assertSee('21,000', false)
            ->assertSee('210,000', false);
    }

    public function test_the_pricing_page_shows_which_plan_unlocks_which_module(): void
    {
        $response = $this->get(route('marketing.pricing'))->assertOk();

        $response->assertSee('What each plan unlocks')
            ->assertSee('Opes Events (ticketing &amp; QR check-in)', false)
            ->assertSee('Loyalty program &amp; printed cards', false)
            ->assertSee('Opes Forms', false);
    }

    public function test_the_contact_page_renders(): void
    {
        $this->get(route('marketing.contact'))->assertOk();
    }

    public function test_the_privacy_page_renders(): void
    {
        $this->get(route('marketing.privacy'))
            ->assertOk()
            ->assertSee('Privacy Policy');
    }

    public function test_the_terms_page_renders(): void
    {
        $this->get(route('marketing.terms'))
            ->assertOk()
            ->assertSee('Terms of Service');
    }

    public function test_footer_links_to_privacy_and_terms(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee(route('marketing.privacy'), false)
            ->assertSee(route('marketing.terms'), false);
    }

    public function test_a_valid_contact_submission_queues_a_notification_and_redirects(): void
    {
        Notification::fake();

        $response = $this->post(route('marketing.contact.store'), [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'message' => 'Which plan fits a five-person team?',
        ]);

        $response->assertRedirect(route('marketing.contact'));
        $response->assertSessionHas('status');

        Notification::assertSentOnDemand(ContactMessageNotification::class);
    }

    public function test_an_invalid_contact_submission_shows_validation_errors(): void
    {
        $response = $this->from(route('marketing.contact'))->post(route('marketing.contact.store'), [
            'name' => 'Ada Obi',
            'message' => 'No email attached.',
        ]);

        $response->assertRedirect(route('marketing.contact'));
        $response->assertSessionHasErrors(['email']);
    }

    public function test_the_contact_honeypot_rejects_bots(): void
    {
        $response = $this->post(route('marketing.contact.store'), [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'Spam',
            'website_url' => 'https://spam.example',
        ]);

        $response->assertSessionHasErrors(['website_url']);
    }
}
