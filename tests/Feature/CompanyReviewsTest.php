<?php

namespace Tests\Feature;

use App\Livewire\Business\Reviews;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->company = $this->makeCompany('Acme Ltd', 'acme');
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        $company = Company::create([
            'slug' => $slug,
            'name' => $name,
            'owner_id' => $this->user->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($company, $this->user);

        return $company;
    }

    protected function makeReview(array $attributes = []): CompanyReview
    {
        return CompanyReview::create($attributes + [
            'author_name' => 'Chika A.',
            'rating' => 5,
            'body' => 'Excellent service, quick turnaround.',
            'is_published' => false,
        ]);
    }

    public function test_a_visitor_submission_is_stored_unpublished_and_hidden_from_the_public_page(): void
    {
        // A public visitor: no session, no current company.
        app(CurrentCompany::class)->set(null);

        $this->post(route('profile.business.review', $this->company), [
            'author_name' => 'Bola T.',
            'rating' => 4,
            'body' => 'Great experience overall.',
        ])
            ->assertRedirect(route('profile.business', $this->company))
            ->assertSessionHas('review_status', 'Thanks — your review is awaiting approval.');

        $review = CompanyReview::query()->acrossAllCompanies()->firstOrFail();

        $this->assertSame($this->company->id, $review->company_id);
        $this->assertFalse($review->is_published);
        $this->assertSame(hash('sha256', '127.0.0.1'), $review->submitted_ip_hash);

        // Unmoderated, so the public page shows neither the review nor a rating.
        $this->get(route('profile.business', $this->company))
            ->assertOk()
            ->assertDontSee('Great experience overall.')
            ->assertDontSee('What customers say');
    }

    public function test_published_reviews_appear_publicly_with_their_average(): void
    {
        $this->makeReview(['author_name' => 'Ngozi E.', 'rating' => 5, 'body' => 'Flawless delivery every time.', 'is_published' => true]);
        $this->makeReview(['author_name' => 'Sade K.', 'rating' => 4, 'body' => 'Very responsive team.', 'is_published' => true]);
        $this->makeReview(['author_name' => 'Hidden P.', 'rating' => 1, 'body' => 'Still awaiting moderation.']);

        app(CurrentCompany::class)->set(null);

        $this->get(route('profile.business', $this->company))
            ->assertOk()
            ->assertSee('Flawless delivery every time.')
            ->assertSee('Very responsive team.')
            ->assertSee('4.5 / 5')
            ->assertSee('2 reviews')
            ->assertDontSee('Still awaiting moderation.');
    }

    public function test_a_filled_honeypot_drops_the_submission_without_telling_the_bot(): void
    {
        app(CurrentCompany::class)->set(null);

        $this->post(route('profile.business.review', $this->company), [
            'author_name' => 'Bot Name',
            'rating' => 5,
            'body' => 'Spam body.',
            'website_url' => 'https://spam.example',
        ])
            ->assertRedirect(route('profile.business', $this->company))
            ->assertSessionHas('review_status');

        $this->assertSame(0, CompanyReview::query()->acrossAllCompanies()->count());
    }

    public function test_invalid_submissions_are_rejected(): void
    {
        app(CurrentCompany::class)->set(null);

        $this->post(route('profile.business.review', $this->company), [
            'author_name' => str_repeat('a', 81),
            'rating' => 9,
            'body' => '',
        ])->assertSessionHasErrors(['author_name', 'rating', 'body']);

        $this->assertSame(0, CompanyReview::query()->acrossAllCompanies()->count());
    }

    public function test_moderation_requires_the_business_update_ability(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($cashier)->get(route('business.reviews'))->assertForbidden();
        $this->actingAs($this->user)->get(route('business.reviews'))->assertOk();
    }

    public function test_reviews_can_be_published_unpublished_and_deleted(): void
    {
        $review = $this->makeReview();

        Livewire::actingAs($this->user)
            ->test(Reviews::class)
            ->call('publish', $review->id);

        $this->assertTrue($review->fresh()->is_published);

        Livewire::actingAs($this->user)
            ->test(Reviews::class)
            ->call('unpublish', $review->id);

        $this->assertFalse($review->fresh()->is_published);

        Livewire::actingAs($this->user)
            ->test(Reviews::class)
            ->call('delete', $review->id);

        $this->assertSame(0, CompanyReview::query()->acrossAllCompanies()->count());
    }

    public function test_moderation_cannot_reach_another_companys_reviews(): void
    {
        $outsider = User::factory()->create();

        $foreign = Company::create([
            'slug' => 'foreign-co',
            'name' => 'Foreign Co',
            'owner_id' => $outsider->id,
        ]);

        $foreignReview = app(CurrentCompany::class)->as($foreign, fn () => $this->makeReview());

        // The tenant scope hides the foreign row, so moderation 404s on it.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->user)
            ->test(Reviews::class)
            ->call('publish', $foreignReview->id);

        $this->assertFalse($foreignReview->fresh()->is_published);
    }
}
