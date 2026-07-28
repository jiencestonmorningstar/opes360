<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Item;
use App\Models\Receipt;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smoke coverage for every authenticated page: each one renders for a real user
 * against the demo dataset. Catches the whole class of breakage — a missing
 * route, an undefined view variable, a lazy-load violation — that only shows up
 * when a page is actually requested.
 */
class PlatformPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-07-27 09:30:00', 'UTC'));

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoCompanySeeder::class);

        $this->user = User::where('email', 'john@opesware.com')->firstOrFail();
        $this->company = Company::firstOrFail();
        app(CurrentCompany::class)->set($this->company);
    }

    public static function pageProvider(): array
    {
        return [
            'dashboard' => ['/', 'Good morning'],
            'sales' => ['/sales', 'Sales'],
            'customers' => ['/customers', 'Customers'],
            'products' => ['/products', 'Products'],
            'product create' => ['/products/create', 'New Product'],
            'business' => ['/business', 'Business QR'],
            'stationery' => ['/business/stationery', 'Stationery'],
            'payments' => ['/payments', 'Payments'],
            'reports' => ['/reports', 'Reports'],
            'calendar' => ['/calendar', 'Calendar'],
            'settings' => ['/settings', 'Your Profile'],
            'help' => ['/help', 'Help &amp; Support'],
            'new document' => ['/documents/create?type=invoice', 'New Invoice'],
        ];
    }

    /** @dataProvider pageProvider */
    public function test_authenticated_pages_render(string $path, string $expected): void
    {
        $this->actingAs($this->user)
            ->get($path)
            ->assertOk()
            ->assertSee($expected, false);
    }

    public function test_every_page_redirects_guests_to_login(): void
    {
        foreach (array_column(self::pageProvider(), 0) as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_the_customer_detail_page_shows_history(): void
    {
        $contact = Contact::where('name', 'Alpha Builders')->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('customers.show', $contact))
            ->assertOk()
            ->assertSee('Alpha Builders')
            ->assertSee('Total billed')
            ->assertSee('Documents');
    }

    public function test_the_product_edit_page_loads_an_item(): void
    {
        $item = Item::query()->products()->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('products.edit', $item))
            ->assertOk()
            ->assertSee($item->name);
    }

    public function test_the_document_print_view_renders_with_a_qr(): void
    {
        $document = Document::query()->invoices()->whereNotNull('verification_token_id')->firstOrFail();

        $response = $this->actingAs($this->user)->get(route('documents.print', $document));

        $response->assertOk()->assertSee($document->number);
        // The QR is inlined into the print view rather than fetched, so it is
        // present when the sheet prints from a device that is offline.
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_the_receipt_print_view_renders(): void
    {
        $receipt = Receipt::firstOrFail();

        $this->actingAs($this->user)
            ->get(route('receipts.print', $receipt))
            ->assertOk()
            ->assertSee($receipt->number)
            ->assertSee('PAID');
    }

    public function test_the_public_business_profile_is_reachable_without_auth(): void
    {
        app(CurrentCompany::class)->set(null);

        $this->get(route('profile.business', $this->company))
            ->assertOk()
            ->assertSee('Opesware Technologies')
            ->assertSee('Verified business');
    }

    public function test_the_vcard_downloads_as_a_contact_file(): void
    {
        app(CurrentCompany::class)->set(null);

        $response = $this->get(route('profile.vcard', $this->company));

        $response->assertOk()->assertHeader('Content-Type', 'text/vcard; charset=utf-8');
        $this->assertStringContainsString('FN:Opesware Technologies', $response->getContent());
    }

    public function test_stationery_print_views_render_each_asset(): void
    {
        foreach (['letterhead', 'card', 'stamp'] as $asset) {
            $this->actingAs($this->user)
                ->get(route('stationery.print', ['asset' => $asset]))
                ->assertOk()
                ->assertSee('Opesware Technologies');
        }
    }

    public function test_printed_stationery_carries_an_inlined_qr(): void
    {
        // Letterheads and cards carry the business QR, inlined rather than
        // fetched so it is present when printing from an offline device. The
        // stamp deliberately has none: rubber cannot hold that detail.
        foreach (['letterhead' => true, 'card' => true, 'stamp' => false] as $asset => $expectsQr) {
            $content = $this->actingAs($this->user)
                ->get(route('stationery.print', ['asset' => $asset]))
                ->getContent();

            $expectsQr
                ? $this->assertStringContainsString('<svg', $content, "{$asset} should carry a QR")
                : $this->assertStringNotContainsString('<svg', $content, "{$asset} should not carry a QR");
        }
    }

    public function test_an_unknown_stationery_asset_falls_back_to_the_letterhead(): void
    {
        $this->actingAs($this->user)
            ->get(route('stationery.print', ['asset' => 'poster']))
            ->assertOk()
            ->assertSee('letterhead', false);
    }

    public function test_reports_export_streams_csv(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('reports'))
            ->assertOk();

        $response->assertSee('Export CSV');
    }
}
