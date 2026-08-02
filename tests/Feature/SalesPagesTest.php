<?php

namespace Tests\Feature;

use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Sales\Index as SalesIndex;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SalesPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen before seeding — the demo dataset is anchored to "today".
        $this->travelTo(Carbon::parse('2026-07-27 09:30:00', 'UTC'));

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoCompanySeeder::class);

        $this->user = User::where('email', 'john@opesware.com')->firstOrFail();
        app(CurrentCompany::class)->set(Company::firstOrFail());
    }

    public function test_the_sales_page_lists_invoices(): void
    {
        // The demo week includes future-dated invoices (the design's full-week
        // chart requires them), and issue-date ordering legitimately puts those
        // first — so the first page is asserted loosely and exact rows are
        // pinned through search and filters in the tests below.
        Livewire::actingAs($this->user)
            ->test(SalesIndex::class)
            ->assertOk()
            ->assertSee('INV-')
            ->assertSet('type', 'invoice');
    }

    public function test_search_narrows_by_number_and_customer(): void
    {
        $year = now()->format('Y');

        Livewire::actingAs($this->user)
            ->test(SalesIndex::class)
            ->set('search', 'Alpha Builders')
            ->assertSee("INV-{$year}-00017")
            ->assertDontSee("INV-{$year}-00018");
    }

    public function test_the_pending_filter_hides_paid_documents(): void
    {
        $year = now()->format('Y');

        Livewire::actingAs($this->user)
            ->test(SalesIndex::class)
            ->call('setState', 'pending')
            ->assertSee("INV-{$year}-00017")   // Alpha Builders, unpaid
            ->assertDontSee("INV-{$year}-00018"); // Tech Core, paid
    }

    public function test_an_unknown_type_falls_back_to_invoices(): void
    {
        Livewire::actingAs($this->user)
            ->test(SalesIndex::class)
            ->call('setType', 'napkin')
            ->assertSet('type', 'invoice');
    }

    public function test_the_customers_page_lists_customers_with_amounts_owing(): void
    {
        Livewire::actingAs($this->user)
            ->test(CustomersIndex::class)
            ->assertOk()
            // Alpha Builders owes 340 and sorts to the top of the list.
            ->assertSeeInOrder(['Alpha Builders', 'FCFA340', 'owing']);
    }

    public function test_customer_search_matches_names(): void
    {
        Livewire::actingAs($this->user)
            ->test(CustomersIndex::class)
            ->set('search', 'Mega')
            ->assertSee('Mega Shop')
            ->assertDontSee('Tech Core Ltd');
    }

    public function test_the_document_page_shows_lines_payments_and_verification(): void
    {
        $year = now()->format('Y');

        $document = Document::query()
            ->where('number', "INV-{$year}-00018")
            ->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee("INV-{$year}-00018")
            ->assertSee('Tech Core Ltd')
            ->assertSee('FCFA250')
            ->assertSee('Verified authentic');
    }

    public function test_a_document_from_another_company_is_not_reachable(): void
    {
        $year = now()->format('Y');

        $document = Document::query()
            ->where('number', "INV-{$year}-00018")
            ->firstOrFail();

        $outsider = User::factory()->create();

        // No company membership → tenant scope yields nothing → 404, not 403:
        // the URL must not even confirm the document exists.
        $this->actingAs($outsider)
            ->get(route('documents.show', $document))
            ->assertNotFound();
    }
}
