<?php

namespace Tests\Feature;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentShare;
use App\Models\BusinessDocumentSignature;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Form;
use App\Models\Position;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\VerificationToken;
use App\Services\TeamInvitations;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The hardening sweep for every public token route.
 *
 * Each of these pages is reached by a stranger holding a token, with no
 * session and no current company. The invariant they all share: company A's
 * token must never render a word of company B's data, no matter what state
 * the CurrentCompany singleton was left in by a previous request.
 */
class PublicTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected Company $alpha;

    protected Company $bravo;

    protected User $alphaOwner;

    protected User $bravoOwner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->alpha, $this->alphaOwner] = $this->makeCompany('Alpha Traders', 'alpha-traders');
        [$this->bravo, $this->bravoOwner] = $this->makeCompany('Bravo Rivals', 'bravo-rivals');

        // A public visitor starts with no tenant at all.
        app(CurrentCompany::class)->set(null);
    }

    /** @return array{0: Company, 1: User} */
    protected function makeCompany(string $name, string $slug): array
    {
        $owner = User::factory()->create();

        $company = Company::create([
            'slug' => $slug,
            'name' => $name,
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($company, $owner);
        $owner->forceFill(['current_company_id' => $company->id])->save();

        $company->forceFill(['modules' => ['logistics' => true, 'service' => true]])->save();
        Modules::flush();

        return [$company, $owner];
    }

    protected function as(Company $company, callable $callback): mixed
    {
        return app(CurrentCompany::class)->as($company, $callback);
    }

    /**
     * One URL per public token family, each pointing at a company-A record.
     *
     * Keys are the first URI segment of the route. The guard test below fails
     * when a public token route exists whose first segment is not a key here —
     * that is how a future public route joins this sweep by default.
     *
     * @return array<string, string>
     */
    protected function publicUrls(): array
    {
        return [
            'v' => '/v/'.$this->companyToken($this->alpha)->token,
            'track' => '/track/'.$this->shipmentFor($this->alpha)->tracking_token,
            'triage' => '/triage/'.$this->companyToken($this->alpha)->token,
            'jobs' => '/jobs/'.$this->vacancyFor($this->alpha)->share_token,
            'share' => '/share/'.$this->shareFor($this->alpha)->share_token,
            'sign' => '/sign/'.$this->signatureFor($this->alpha)->signing_token,
            'e' => '/e/'.$this->eventFor($this->alpha)->share_token,
            'f' => '/f/'.$this->formFor($this->alpha)->share_token,
            'invitations' => '/invitations/'.$this->invitationTokenFor($this->alpha),
        ];
    }

    protected function invitationTokenFor(Company $company): string
    {
        $invitee = $this->as($company, fn () => app(TeamInvitations::class)->invite(
            $company,
            Str::lower(Str::random(8)).'@example.com',
            Role::where('slug', Role::CASHIER)->firstOrFail(),
            null,
            User::find($company->owner_id),
        ));

        return (string) $company->users()
            ->where('users.id', $invitee->id)
            ->first()
            ->pivot
            ->invitation_token;
    }

    protected function companyToken(Company $company): VerificationToken
    {
        return $this->as($company, fn () => VerificationToken::create([
            'token' => Str::random(22),
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ]));
    }

    protected function shipmentFor(Company $company): Shipment
    {
        return $this->as($company, function () use ($company) {
            $sender = Contact::create(['name' => $company->name.' Sender']);
            $receiver = Contact::create(['name' => $company->name.' Receiver']);

            return Shipment::create([
                'reference' => 'SHP-'.Str::upper(Str::random(5)),
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'cargo_description' => 'Crated goods',
                'from_location' => 'Douala',
                'to_location' => 'Yaounde',
                'status' => 'booked',
                'tracking_token' => Str::random(40),
            ]);
        });
    }

    protected function vacancyFor(Company $company): Vacancy
    {
        return $this->as($company, function () {
            $position = Position::create(['title' => 'Storekeeper']);

            return Vacancy::create([
                'position_id' => $position->id,
                'status' => 'open',
                'share_token' => Str::random(32),
            ]);
        });
    }

    protected function paperFor(Company $company): BusinessDocument
    {
        return $this->as($company, fn () => BusinessDocument::create([
            'template' => 'service_agreement',
            'title' => 'Service agreement',
            'reference' => 'DOC-'.Str::upper(Str::random(5)),
            'recipient' => 'Un Client',
            'fields' => ['client_name' => 'Un Client'],
            'body' => 'The agreed terms.',
            'status' => 'issued',
            'created_by' => $company->owner_id,
        ]));
    }

    protected function shareFor(Company $company): BusinessDocumentShare
    {
        $paper = $this->paperFor($company);

        return $this->as($company, fn () => BusinessDocumentShare::create([
            'business_document_id' => $paper->id,
            'share_token' => Str::random(32),
            'created_by' => $company->owner_id,
        ]));
    }

    protected function signatureFor(Company $company): BusinessDocumentSignature
    {
        $paper = $this->paperFor($company);

        return $this->as($company, fn () => BusinessDocumentSignature::create([
            'business_document_id' => $paper->id,
            'signer_name' => 'Jean Signer',
            'signer_email' => 'jean@example.com',
            'signing_token' => Str::random(32),
        ]));
    }

    protected function eventFor(Company $company): Event
    {
        return $this->as($company, function () use ($company) {
            $event = Event::create([
                'title' => $company->name.' Launch Night',
                'starts_at' => now()->addWeek(),
                'status' => 'published',
                'share_token' => Event::newShareToken(),
            ]);

            $event->ticketTypes()->create([
                'company_id' => $company->id,
                'name' => 'General Admission',
                'price' => 25,
                'quantity' => 5,
            ]);

            return $event;
        });
    }

    protected function formFor(Company $company): Form
    {
        return $this->as($company, fn () => Form::create([
            'title' => $company->name.' Enquiry',
            'fields' => [
                ['id' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true],
            ],
            'status' => 'open',
            'share_token' => Str::random(32),
        ]));
    }

    /* ---------------------------------------------------------------- */

    public function test_no_public_token_route_renders_another_tenants_name(): void
    {
        foreach ($this->publicUrls() as $family => $url) {
            app(CurrentCompany::class)->set(null);

            $response = $this->get($url);

            $this->assertLessThan(500, $response->status(), "Public route [{$family}] {$url} errored.");
            $response->assertDontSee('Bravo Rivals');
        }
    }

    /**
     * The same sweep with the singleton deliberately poisoned with company B —
     * the long-lived-worker scenario. The token must still win.
     */
    public function test_a_stale_singleton_never_bleeds_into_a_public_page(): void
    {
        foreach ($this->publicUrls() as $family => $url) {
            app(CurrentCompany::class)->set($this->bravo);

            $response = $this->get($url);

            $this->assertLessThan(500, $response->status(), "Public route [{$family}] {$url} errored.");
            $response->assertDontSee('Bravo Rivals');
        }
    }

    /**
     * Fails when a public token route exists that this sweep does not cover.
     * A new `/x/{token}` route must be added to publicUrls() to ship.
     */
    public function test_the_sweep_covers_every_public_token_route(): void
    {
        $covered = array_keys($this->publicUrls());

        $publicSegments = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => (bool) preg_match('#^([a-z]+)/\{token\}#', $uri))
            ->map(fn (string $uri) => explode('/', $uri)[0])
            ->unique()
            ->values();

        $this->assertNotEmpty($publicSegments->all());

        foreach ($publicSegments as $segment) {
            $this->assertContains(
                $segment,
                $covered,
                "Public token route family [{$segment}] is not covered by PublicTenancyTest::publicUrls().",
            );
        }
    }

    /* -------- 4.3: the loyalty branch must respect the token's tenant ----- */

    public function test_a_loyalty_token_never_renders_a_cross_tenant_contact(): void
    {
        // A corrupted token: company A's token pointing at company B's contact.
        $bravoContact = $this->as($this->bravo, fn () => Contact::create(['name' => 'Bravo Secret Customer']));

        $token = $this->as($this->alpha, fn () => VerificationToken::create([
            'token' => Str::random(22),
            'subject_type' => Contact::class,
            'subject_id' => $bravoContact->id,
        ]));

        app(CurrentCompany::class)->set(null);

        $this->get('/v/'.$token->token)
            ->assertOk()
            ->assertDontSee('Bravo Secret Customer');
    }

    public function test_a_loyalty_token_still_renders_its_own_contact(): void
    {
        $contact = $this->as($this->alpha, fn () => Contact::create(['name' => 'Fideline Customer']));

        $token = $this->as($this->alpha, fn () => VerificationToken::create([
            'token' => Str::random(22),
            'subject_type' => Contact::class,
            'subject_id' => $contact->id,
        ]));

        app(CurrentCompany::class)->set(null);

        $this->get('/v/'.$token->token)
            ->assertOk()
            ->assertSee('Fideline Customer');
    }

    /* -------- 4.1: the guest branch must clear the singleton -------------- */

    public function test_a_guest_request_clears_a_stale_current_company(): void
    {
        app(CurrentCompany::class)->set($this->bravo);

        $this->get('/v/not-a-real-token');

        $this->assertNull(app(CurrentCompany::class)->get());
    }

    /* -------- 4.2: the event page must resolve as its own tenant ---------- */

    public function test_the_event_page_renders_its_own_tickets_with_no_current_company(): void
    {
        $event = $this->eventFor($this->alpha);

        app(CurrentCompany::class)->set(null);

        $this->get('/e/'.$event->share_token)
            ->assertOk()
            ->assertSee('Alpha Traders')
            ->assertSee('General Admission');
    }

    public function test_a_purchase_succeeds_with_no_current_company(): void
    {
        $event = $this->eventFor($this->alpha);
        $type = $event->ticketTypes()->withoutGlobalScopes()->first();

        app(CurrentCompany::class)->set(null);

        $this->post('/e/'.$event->share_token, [
            'buyer_name' => 'Ama Buyer',
            'buyer_email' => 'ama@example.com',
            'quantities' => [$type->id => 2],
        ])->assertRedirect('/e/'.$event->share_token.'/tickets');

        $this->get('/e/'.$event->share_token.'/tickets')
            ->assertOk()
            ->assertSee('Ama Buyer');
    }

    public function test_the_event_page_ignores_a_stale_singleton_from_another_tenant(): void
    {
        $event = $this->eventFor($this->alpha);
        $this->eventFor($this->bravo);

        app(CurrentCompany::class)->set($this->bravo);

        $this->get('/e/'.$event->share_token)
            ->assertOk()
            ->assertSee('Alpha Traders')
            ->assertSee('General Admission')
            ->assertDontSee('Bravo Rivals');
    }

    /* -------- 4.6: an orphaned token must 404, not fatal ------------------ */

    public function test_a_token_whose_company_is_gone_is_a_404_not_a_500(): void
    {
        $token = $this->companyToken($this->alpha);
        $share = $this->shareFor($this->alpha);
        $signature = $this->signatureFor($this->alpha);

        Company::query()->whereKey($this->alpha->id)->delete();
        app(CurrentCompany::class)->set(null);

        $this->get('/v/'.$token->token)->assertNotFound();
        $this->get('/share/'.$share->share_token)->assertNotFound();
        $this->get('/sign/'.$signature->signing_token)->assertNotFound();
    }

    /* -------- 4.7: suspension reaches signing and share links ------------- */

    public function test_share_and_signing_links_go_dark_when_the_company_is_suspended(): void
    {
        $share = $this->shareFor($this->alpha);
        $signature = $this->signatureFor($this->alpha);

        $this->alpha->forceFill(['suspended_at' => now()])->save();
        app(CurrentCompany::class)->set(null);

        $this->get('/share/'.$share->share_token)->assertNotFound();
        $this->get('/sign/'.$signature->signing_token)->assertNotFound();
    }
}
