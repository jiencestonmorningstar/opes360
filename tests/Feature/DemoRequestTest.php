<?php

namespace Tests\Feature;

use App\Console\Commands\ConvertExpiredDemos;
use App\Livewire\Settings\Index;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Event;
use App\Models\Form;
use App\Models\Item;
use App\Models\User;
use App\Notifications\DemoAccountCreatedNotification;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The demo request is the whole "try before you commit" funnel: a public
 * form provisions a working account with one of everything, mails the
 * credentials, and the account converts itself to a trial two weeks later
 * without ever locking anyone out.
 */
class DemoRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_request_page_is_public(): void
    {
        $this->get('/demo')->assertOk()->assertSee('Try a demo');
    }

    public function test_submitting_creates_a_working_account_with_one_of_everything(): void
    {
        Notification::fake();
        $this->seed(RolePermissionSeeder::class);

        $this->post('/demo', [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'business_name' => 'Ada Trading Co',
            'industry' => 'Retail',
        ])->assertRedirect(route('demo.thanks'));

        $user = User::where('email', 'ada@example.com')->first();
        $this->assertNotNull($user);

        $company = Company::where('slug', 'ada-trading-co')->first();
        $this->assertNotNull($company);
        $this->assertTrue($company->isDemo());
        $this->assertNotNull($company->demo_expires_at);
        $this->assertEqualsWithDelta(14, now()->diffInDays($company->demo_expires_at), 1);

        $this->assertSame(1, Contact::acrossAllCompanies()->where('company_id', $company->id)->count());
        $this->assertSame(1, Item::acrossAllCompanies()->where('company_id', $company->id)->count());
        $this->assertSame(1, Document::acrossAllCompanies()->where('company_id', $company->id)->count());
        $this->assertSame(1, BusinessDocument::acrossAllCompanies()->where('company_id', $company->id)->count());
        $this->assertSame(1, Form::acrossAllCompanies()->where('company_id', $company->id)->count());
        $this->assertSame(1, Event::acrossAllCompanies()->where('company_id', $company->id)->count());

        Notification::assertSentTo($user, DemoAccountCreatedNotification::class);
    }

    public function test_the_honeypot_field_blocks_submission(): void
    {
        $this->post('/demo', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'business_name' => 'Bot Co',
            'website_url' => 'http://spam.example',
        ])->assertSessionHasErrors('website_url');

        $this->assertNull(User::where('email', 'bot@example.com')->first());
    }

    public function test_an_existing_email_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/demo', [
            'name' => 'Ada',
            'email' => 'taken@example.com',
            'business_name' => 'Ada Co',
        ])->assertSessionHasErrors('email');
    }

    public function test_the_command_converts_only_expired_demos(): void
    {
        $owner = User::factory()->create();

        $expired = Company::create([
            'slug' => 'expired-co', 'name' => 'Expired Co', 'owner_id' => $owner->id, 'currency' => 'USD',
            'account_type' => 'demo', 'demo_expires_at' => now()->subDay(),
        ]);

        $active = Company::create([
            'slug' => 'active-demo-co', 'name' => 'Active Demo Co', 'owner_id' => $owner->id, 'currency' => 'USD',
            'account_type' => 'demo', 'demo_expires_at' => now()->addDays(5),
        ]);

        $this->artisan(ConvertExpiredDemos::class)->assertSuccessful();

        $this->assertSame('trial', $expired->fresh()->account_type);
        $this->assertNull($expired->fresh()->demo_expires_at);
        $this->assertSame('demo', $active->fresh()->account_type);
    }

    public function test_the_owner_can_end_their_own_demo_early(): void
    {
        $owner = User::factory()->create();

        $company = Company::create([
            'slug' => 'early-co', 'name' => 'Early Co', 'owner_id' => $owner->id, 'currency' => 'USD',
            'account_type' => 'demo', 'demo_expires_at' => now()->addDays(10),
        ]);

        $this->joinCompany($company, $owner);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        Livewire::actingAs($owner)
            ->test(Index::class)
            ->call('endDemo');

        $this->assertSame('trial', $company->fresh()->account_type);
    }
}
