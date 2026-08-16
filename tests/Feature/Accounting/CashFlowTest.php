<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\Accounting\Ledger;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl', 'owner_id' => $this->owner->id,
            'currency' => 'XAF', 'plan' => 'business', 'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);
    }

    public function test_a_business_with_no_movements_reports_zero(): void
    {
        $flow = $this->books()->cashFlow($this->company);

        $this->assertSame(0.0, $flow['net']);
        $this->assertCount(0, $flow['movements']);
    }

    public function test_a_sale_shows_as_cash_coming_in(): void
    {
        $this->sale('2026-03-10', 5000);

        $flow = $this->books()->cashFlow($this->company);

        $this->assertSame(5000.0, $flow['net']);
        $this->assertSame(5000.0, $flow['operating']);
    }

    public function test_a_purchase_shows_as_cash_going_out(): void
    {
        $this->sale('2026-03-10', 5000);
        $this->purchase('2026-03-12', 2000);

        $flow = $this->books()->cashFlow($this->company);

        $this->assertSame(3000.0, $flow['net']);
    }

    /** Sales and purchases are operating; an OD entry is not guessed at. */
    public function test_an_other_entry_is_reported_unclassified_rather_than_guessed(): void
    {
        $this->other('2026-03-15', 1000);

        $flow = $this->books()->cashFlow($this->company);

        $this->assertSame(1000.0, $flow['unclassified']);
        $this->assertSame(0.0, $flow['operating']);
    }

    public function test_movements_before_the_window_form_the_opening_balance(): void
    {
        $this->sale('2026-02-10', 4000);
        $this->sale('2026-03-10', 1000);

        $flow = $this->books()->cashFlow($this->company, '2026-03-01', '2026-03-31');

        $this->assertSame(4000.0, $flow['opening']);
        $this->assertSame(1000.0, $flow['net']);
        $this->assertSame(5000.0, $flow['closing']);
    }

    public function test_the_window_excludes_movements_after_it(): void
    {
        $this->sale('2026-03-10', 1000);
        $this->sale('2026-04-10', 9999);

        $flow = $this->books()->cashFlow($this->company, '2026-03-01', '2026-03-31');

        $this->assertSame(1000.0, $flow['net']);
    }

    public function test_movements_come_back_in_date_order(): void
    {
        $this->sale('2026-03-20', 100);
        $this->sale('2026-03-05', 200);

        $dates = $this->books()->cashFlow($this->company)['movements']
            ->pluck('date')->map(fn ($d) => $d->toDateString())->all();

        $this->assertSame(['2026-03-05', '2026-03-20'], $dates);
    }

    protected function sale(string $date, float $amount): void
    {
        app(Ledger::class)->post($this->company, 'VE', $date, [
            ['account' => 'cash', 'debit' => $amount],
            ['account' => 'sales_goods', 'credit' => $amount],
        ]);
    }

    protected function purchase(string $date, float $amount): void
    {
        app(Ledger::class)->post($this->company, 'AC', $date, [
            ['account' => 'purchases', 'debit' => $amount],
            ['account' => 'cash', 'credit' => $amount],
        ]);
    }

    protected function other(string $date, float $amount): void
    {
        app(Ledger::class)->post($this->company, 'OD', $date, [
            ['account' => 'cash', 'debit' => $amount],
            ['account' => 'sales_services', 'credit' => $amount],
        ]);
    }

    protected function books(): Books
    {
        return app(Books::class);
    }
}
