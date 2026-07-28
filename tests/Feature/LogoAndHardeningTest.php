<?php

namespace Tests\Feature;

use App\Livewire\Business\Logo;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\LogoComposer;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LogoAndHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'industry' => 'Technology',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
        ]);

        // The membership pivot matters: SetCurrentCompany re-checks it on every
        // request, so a user without one resolves to no company at all.
        $this->company->users()->attach($this->user->id, [
            'role_id' => Role::where('slug', Role::OWNER)->value('id'),
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    public function test_the_composer_emits_valid_standalone_svg(): void
    {
        $svg = app(LogoComposer::class)->render('Acme Ltd', 'We deliver', [
            'palette' => 'forest',
            'mark' => 'leaf',
            'layout' => 'horizontal',
        ]);

        $this->assertStringStartsWith('<svg', trim($svg));
        $this->assertStringContainsString('Acme Ltd', $svg);
        $this->assertStringContainsString('We deliver', $svg);
        // Parseable as XML, which is what "clean vector" has to mean in practice.
        $this->assertNotFalse(simplexml_load_string($svg));
    }

    public function test_company_names_are_escaped_rather_than_injected(): void
    {
        $svg = app(LogoComposer::class)->render('Ampersand & <script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('&amp;', $svg);
        $this->assertNotFalse(simplexml_load_string($svg));
    }

    public function test_unknown_options_fall_back_instead_of_breaking(): void
    {
        $svg = app(LogoComposer::class)->render('Acme Ltd', '', [
            'palette' => 'not-a-palette',
            'mark' => 'not-a-mark',
            'layout' => 'not-a-layout',
        ]);

        $this->assertNotFalse(simplexml_load_string($svg));
    }

    public function test_the_canvas_grows_so_long_names_are_not_clipped(): void
    {
        $composer = app(LogoComposer::class);

        foreach (['horizontal', 'stacked', 'badge'] as $layout) {
            $short = simplexml_load_string($composer->render('Ace', '', ['layout' => $layout]));
            $long = simplexml_load_string(
                $composer->render('Opesware Technologies International Limited', '', ['layout' => $layout])
            );

            [, , $shortWidth] = explode(' ', (string) $short['viewBox']);
            [, , $longWidth] = explode(' ', (string) $long['viewBox']);

            $this->assertGreaterThan(
                (float) $shortWidth,
                (float) $longWidth,
                "The {$layout} canvas must widen for a long name rather than clipping it."
            );
        }
    }

    public function test_suggestions_lead_with_the_industry_hint(): void
    {
        $suggestions = app(LogoComposer::class)->suggestions('Agriculture', 6);

        $this->assertCount(6, $suggestions);
        // Agriculture maps to forest/leaf, which should be offered first.
        $this->assertSame('forest', $suggestions[0]['palette']);
        $this->assertSame('leaf', $suggestions[0]['mark']);
    }

    public function test_saving_a_logo_writes_an_svg_and_records_the_brand_tokens(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->user)
            ->test(Logo::class)
            ->set('palette', 'royal')
            ->set('mark', 'hexagon')
            ->set('layout', 'badge')
            ->call('save')
            ->assertHasNoErrors();

        $company = $this->company->fresh();

        $this->assertNotNull($company->logo_path);
        Storage::disk('public')->assertExists($company->logo_path);

        // The configuration is stored so the logo can be regenerated at any size
        // and later assets stay consistent with it.
        $this->assertSame('royal', $company->brandToken('palette'));
        $this->assertSame('hexagon', $company->brandToken('mark'));
        $this->assertSame('badge', $company->brandToken('layout'));
    }

    public function test_the_logo_downloads_as_an_svg_file(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('logo.download', ['palette' => 'ocean', 'mark' => 'orbit', 'layout' => 'stacked']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('Content-Disposition', 'attachment; filename="acme-logo.svg"');
    }

    public function test_security_headers_are_present_on_public_pages(): void
    {
        // Public pages matter most here: a stranger opens them from a printed code.
        app(CurrentCompany::class)->set(null);

        $this->get(route('profile.business', $this->company))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_changes_are_written_to_the_audit_log_with_a_diff(): void
    {
        $contact = Contact::create(['name' => 'Before Name']);

        $this->actingAs($this->user);
        $contact->update(['name' => 'After Name']);

        $entry = ActivityLog::query()
            ->where('subject_id', $contact->id)
            ->where('event', 'updated')
            ->firstOrFail();

        $this->assertSame('Before Name', data_get($entry->properties, 'before.name'));
        $this->assertSame('After Name', data_get($entry->properties, 'after.name'));
        $this->assertSame($this->company->id, $entry->company_id);
    }

    public function test_the_audit_log_redacts_secrets(): void
    {
        $this->actingAs($this->user);

        $this->company->update(['tax_id' => 'TIN-SECRET-1234']);

        $entry = ActivityLog::query()
            ->where('subject_id', $this->company->id)
            ->where('event', 'updated')
            ->latest('created_at')
            ->firstOrFail();

        $encoded = json_encode($entry->properties);

        $this->assertStringNotContainsString('TIN-SECRET-1234', $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
    }

    public function test_high_churn_models_are_kept_out_of_the_audit_log(): void
    {
        $this->actingAs($this->user);

        $item = Item::create(['name' => 'Tracked', 'price' => 5, 'track_stock' => true]);
        $item->movements()->create([
            'company_id' => $item->company_id,
            'quantity' => 10,
            'reason' => 'opening',
            'occurred_at' => now(),
        ]);

        // The item is logged; its append-only movements deliberately are not.
        $this->assertTrue(ActivityLog::where('subject_type', Item::class)->exists());
        $this->assertFalse(ActivityLog::where('subject_type', StockMovement::class)->exists());
    }
}
