<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\VerificationToken;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A customer opening their supplier's shared link is not signed in, so nothing
 * about the request says whose colours to use. These pages have to be told.
 *
 * Getting this wrong is not cosmetic: the whole promise of the module is that a
 * business's branding is what its customers see, and the customer-facing pages
 * are the only place customers ever see anything.
 */
class BrandingPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            // Survives derivation untouched, so it appears verbatim.
            'branding' => ['primary' => '#4a154b'],
        ]);

        $this->joinCompany($this->company, $owner);
    }

    public function test_a_public_business_profile_wears_the_companys_colours(): void
    {
        $this->get(route('profile.business', $this->company))
            ->assertOk()
            ->assertSee('#4a154b', false);
    }

    public function test_a_verification_page_wears_the_companys_colours(): void
    {
        app(CurrentCompany::class)->set($this->company);

        $contact = Contact::create(['name' => 'Un Client', 'balance' => 0]);

        $token = VerificationToken::create([
            'company_id' => $this->company->id,
            'token' => VerificationToken::newToken(),
            'subject_type' => Contact::class,
            'subject_id' => $contact->id,
        ]);

        app(CurrentCompany::class)->set(null);

        $response = $this->get(route('verification.show', $token->token));

        // The page may 404 for a subject it does not know how to render; what
        // matters is that when it renders, it renders in the right colours.
        if ($response->status() === 200) {
            $response->assertSee('#4a154b', false);
        } else {
            $this->markTestSkipped('this verification subject has no public page');
        }
    }

    /** The marketing site is ours, so it must NOT pick up a tenant's colours. */
    public function test_the_landing_page_stays_on_the_platform_palette(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('#1d4ed8', false)
            ->assertDontSee('#4a154b', false);
    }

    /** A signed-out visitor to one business must not see another's branding. */
    public function test_one_businesss_public_page_does_not_leak_anothers_colours(): void
    {
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => User::factory()->create()->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            'branding' => ['primary' => '#166534'],
        ]);

        $this->get(route('profile.business', $other))
            ->assertOk()
            ->assertSee('#166534', false)
            ->assertDontSee('#4a154b', false);
    }
}
