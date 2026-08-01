<?php

namespace Tests\Feature;

use App\Livewire\Onboarding\Register;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Item;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\ReviewSubmittedNotification;
use App\Notifications\SubscriptionPaymentFailedNotification;
use App\Notifications\WelcomeNotification;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The events that previously happened in silence.
 *
 * Each of these is a thing the product already did without telling anyone: a
 * failed subscription payment looked identical to a slow one, reviews queued
 * up unseen, stock ran out, and a new business heard nothing at all.
 */
class EventNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Sarl',
            'owner_id' => $this->user->id,
            'currency' => 'XAF',
            'account_type' => 'active',
            'plan' => 'growth',
        ]);

        $this->joinCompany($this->company, $this->user);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    public function test_a_failed_subscription_payment_tells_the_owner_why(): void
    {
        Notification::fake();

        $payment = SubscriptionPayment::create([
            'company_id' => $this->company->id,
            'plan' => 'growth',
            'billing_cycle' => 'monthly',
            'provider' => 'mtn_momo',
            'amount' => 15000,
            'currency' => 'XAF',
            'status' => 'failed',
            'failure_reason' => 'Insufficient balance',
            'external_id' => 'TEST-1',
        ]);

        $this->user->notify(new SubscriptionPaymentFailedNotification($payment));

        Notification::assertSentTo($this->user, SubscriptionPaymentFailedNotification::class,
            function (SubscriptionPaymentFailedNotification $n) {
                $mail = $n->toMail($this->user);
                $body = collect($mail->introLines)->implode(' ');

                // The provider's own words are the actionable part.
                $this->assertStringContainsString('Insufficient balance', $body);
                $this->assertStringContainsString('not been charged', $body);

                return true;
            });
    }

    public function test_a_new_business_is_welcomed(): void
    {
        Notification::fake();

        $this->user->notify(new WelcomeNotification($this->company));

        Notification::assertSentTo($this->user, WelcomeNotification::class,
            function (WelcomeNotification $n) {
                $this->assertStringContainsString('Acme Sarl', $n->toMail($this->user)->subject);

                return true;
            });
    }

    public function test_registering_sends_the_welcome_message(): void
    {
        Notification::fake();

        Livewire::test(Register::class)
            ->set('name', 'Jane Trader')
            ->set('email', 'jane@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('passwordConfirmation', 'correct-horse-battery')
            ->call('continueToBusiness')
            ->set('businessName', 'Jane Trading')
            ->set('currency', 'XAF')
            ->call('finish')
            ->assertHasNoErrors();

        Notification::assertSentTo(
            User::where('email', 'jane@example.com')->firstOrFail(),
            WelcomeNotification::class,
        );
    }

    public function test_a_public_review_reaches_the_business(): void
    {
        Notification::fake();

        $this->post(route('profile.business.review', $this->company), [
            'author_name' => 'A Customer',
            'rating' => 5,
            'body' => 'Fast and fair.',
        ])->assertRedirect();

        // Unpublished until approved — so somebody has to be told it is there.
        $this->assertDatabaseHas('company_reviews', ['author_name' => 'A Customer', 'is_published' => false]);
        Notification::assertSentTo($this->user, ReviewSubmittedNotification::class);
    }

    public function test_low_stock_is_reported_once_per_business(): void
    {
        Notification::fake();

        Item::create([
            'company_id' => $this->company->id,
            'name' => 'Cement 50kg',
            'price' => 6000,
            'track_stock' => true,
            'reorder_level' => 10,
        ]);

        $this->artisan('opes:alert-low-stock')->assertSuccessful();

        Notification::assertSentToTimes($this->user, LowStockNotification::class, 1);
    }

    public function test_stock_above_the_reorder_level_says_nothing(): void
    {
        Notification::fake();

        $item = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Sand (tonne)',
            'price' => 3000,
            'track_stock' => true,
            'reorder_level' => 1,
        ]);

        $item->movements()->create([
            'quantity' => 50,
            'reason' => 'opening',
            'company_id' => $this->company->id,
            'occurred_at' => now(),
        ]);

        $this->artisan('opes:alert-low-stock')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_review_notice_names_the_rating_and_author(): void
    {
        $review = CompanyReview::create([
            'company_id' => $this->company->id,
            'author_name' => 'Marie',
            'rating' => 4,
            'body' => 'Good service.',
            'is_published' => false,
        ]);

        $payload = (new ReviewSubmittedNotification($review))->toArray($this->user);

        $this->assertSame('4★ from Marie', $payload['body']);
    }
}
