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

    /**
     * Every module gets a slot in the grid. This replaced a hero carousel that
     * listed the same six modules the grid below already listed, so the first
     * two screens of the page said the same thing twice.
     */
    public function test_the_home_page_names_every_module_once(): void
    {
        $response = $this->get('/')->assertOk();

        foreach (['Opes Forms', 'Opes Events', 'Loyalty program', 'Public reviews', 'Offline mode', 'SYSCOHADA accounting'] as $module) {
            $response->assertSee($module, false);
        }

        $body = $response->getContent();

        $this->assertSame(1, substr_count($body, 'Opes Events'), 'A module named twice on one page is the duplication this layout removed.');
    }

    public function test_the_home_page_carries_the_three_pillars_and_their_mocks(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Three things a receipt book cannot do')
            // Each pillar is illustrated by a mock built from the design tokens
            // rather than a screenshot; these strings only exist inside them.
            ->assertSee('QT-2026-0042', false)
            ->assertSee('Verified document', false)
            ->assertSee('No connection', false);
    }

    public function test_the_home_page_advertises_the_partner_programme(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('For secretariats and print shops')
            ->assertSee(route('marketing.partners'), false);
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

    /**
     * A 520px-wide matrix left two of the three plans off the side of a 390px
     * phone, behind a scroll with nothing to suggest it was there. Below `md`
     * the same rows are grouped by the plan that unlocks them instead.
     */
    public function test_the_plan_matrix_has_a_mobile_form_that_does_not_scroll_sideways(): void
    {
        $response = $this->get(route('marketing.pricing'))->assertOk();

        $response->assertSee('Included in Basic')
            ->assertSee('Added in Growth')
            ->assertSee('Added in Business')
            // The matrix survives, but only from `md` where it fits.
            ->assertSee('hidden overflow-x-auto md:block', false);
    }

    public function test_the_partner_programme_page_states_the_terms_from_config(): void
    {
        $response = $this->get(route('marketing.partners'))->assertOk();

        $response->assertSee('Secretariats', false)
            ->assertSee('500', false)     // card fee
            ->assertSee('10%', false)     // commission rate
            ->assertSee('How long does the commission last?');
    }

    public function test_the_advertised_partner_terms_come_from_the_same_config_the_ledger_uses(): void
    {
        // A rate quoted on the website and a rate applied to a payment drifting
        // apart is a bug nobody notices until a partner does the arithmetic.
        config()->set('opes.partners.card_fee', 750);
        config()->set('opes.partners.commission_rate', 0.15);

        $this->get(route('marketing.partners'))
            ->assertOk()
            ->assertSee('750', false)
            ->assertSee('15%', false)
            ->assertDontSee('10%', false);
    }

    public function test_the_contact_page_renders(): void
    {
        $this->get(route('marketing.contact'))->assertOk();
    }

    public function test_the_contact_page_shows_direct_contact_details(): void
    {
        $response = $this->get(route('marketing.contact'))->assertOk();

        $response->assertSee('360@opes360.com')
            ->assertSee('nshomejude@gmail.com')
            ->assertSee('+237 670 41 62 38', false)
            ->assertSee('Petite Terrain', false)
            ->assertSee('wa.me/237670416238', false);
    }

    public function test_the_blog_index_lists_posts(): void
    {
        $response = $this->get(route('marketing.blog'))->assertOk();

        $response->assertSee('Why offline-first isn&#039;t a nice-to-have here', false)
            ->assertSee('What a QR code on your invoice is actually proving');
    }

    public function test_a_blog_post_renders(): void
    {
        $this->get(route('marketing.blog.show', 'qr-verification-explained'))
            ->assertOk()
            ->assertSee('What a QR code on your invoice is actually proving')
            ->assertSee('How it works');
    }

    public function test_an_unknown_blog_slug_404s(): void
    {
        $this->get(route('marketing.blog.show', 'does-not-exist'))->assertNotFound();
    }

    public function test_the_mobile_menu_reaches_every_marketing_page(): void
    {
        $response = $this->get('/')->assertOk();

        // The mobile drawer duplicates the desktop nav's links, so both
        // must resolve — a real gap before this: nav links vanished below lg.
        $response->assertSee(route('marketing.blog'), false)
            ->assertSee('aria-label="Open menu"', false);
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
