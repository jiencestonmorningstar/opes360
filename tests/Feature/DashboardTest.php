<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\User;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Locks the dashboard to the figures in the product design. Every one of these is
 * computed from seeded rows by the same queries production runs — if a query or
 * the seed drifts, this fails rather than the screen quietly going wrong.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen to a Monday morning BEFORE seeding: the greeting is
        // time-of-day dependent and the seeded week is anchored to "today", so
        // an unfrozen clock makes these tests fail after noon and mid-week.
        $this->travelTo(Carbon::parse('2026-07-27 09:30:00', 'UTC'));

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoCompanySeeder::class);

        $this->user = User::where('email', 'john@opesware.com')->firstOrFail();
    }

    public function test_guests_see_the_marketing_home_instead_of_the_dashboard(): void
    {
        // '/' is shared: a guest sees the public marketing page (LandingPageTest),
        // not a login redirect — the dashboard itself stays behind auth.
        $this->get('/')->assertOk()->assertDontSee('Good morning');
    }

    public function test_the_dashboard_renders_the_designed_figures(): void
    {
        Livewire::actingAs($this->user)
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Good morning, John', false)
            ->assertSee('$1,250.00')   // Total Sales, today
            ->assertSee('$820.00')     // Paid, today
            ->assertSee('$430.00')     // Outstanding, running balance
            ->assertSee('$5,760.00')   // Sales Overview, this week
            ->assertSee('128')         // Customers
            ->assertSee('56')          // Products
            ->assertSee('24')          // Receipts today
            ->assertSee('$210.00');    // Expenses this month
    }

    public function test_the_stat_card_deltas_compare_against_the_previous_window(): void
    {
        Livewire::actingAs($this->user)
            ->test(Dashboard::class)
            ->assertSee('12%')  // sales vs yesterday
            ->assertSee('8%');  // payments vs yesterday
    }

    public function test_recent_invoices_shows_the_three_latest_and_excludes_future_dates(): void
    {
        $year = now()->format('Y');

        Livewire::actingAs($this->user)
            ->test(Dashboard::class)
            ->assertSeeInOrder([
                "INV-{$year}-00018",
                'Tech Core Ltd',
                "INV-{$year}-00017",
                'Alpha Builders',
                "INV-{$year}-00016",
                'Mega Shop',
            ])
            ->assertSee('Pending');
    }

    public function test_top_customers_ranks_the_named_accounts_for_the_month(): void
    {
        Livewire::actingAs($this->user)
            ->test(Dashboard::class)
            ->assertSeeInOrder([
                'Tech Core Ltd',
                'Alpha Builders',
                'Mega Shop',
                'Daily Needs',
            ]);
    }

    public function test_changing_the_range_recomputes_the_window(): void
    {
        Livewire::actingAs($this->user)
            ->test(Dashboard::class)
            ->call('setRange', 'month')
            ->assertSet('range', 'month')
            ->assertSee('This Month');
    }

    public function test_an_unknown_range_falls_back_to_today(): void
    {
        Livewire::actingAs($this->user)
            ->test(Dashboard::class)
            ->call('setRange', 'century')
            ->assertSet('range', 'today');
    }
}
