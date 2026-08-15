<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\RecurringInvoice;
use App\Models\Role;
use App\Models\User;
use App\Services\RecurringInvoices;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerateRecurringInvoicesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    protected function schedule(array $attributes = []): RecurringInvoice
    {
        return RecurringInvoice::create(array_merge([
            'contact_id' => $this->customer->id,
            'name' => 'Monthly retainer',
            'lines' => [['description' => 'Retainer', 'quantity' => 1, 'unit_price' => 100000]],
            'frequency' => 'monthly',
            'interval' => 1,
            'starts_on' => now()->subDay()->toDateString(),
            'next_run_on' => now()->subDay()->toDateString(),
            'payment_terms_days' => 14,
            'status' => RecurringInvoice::ACTIVE,
            'created_by' => $this->owner->id,
        ], $attributes));
    }

    public function test_it_is_quiet_when_nothing_is_due(): void
    {
        $this->artisan('opes:generate-recurring-invoices')
            ->expectsOutputToContain('Nothing was due.')
            ->assertExitCode(0);
    }

    public function test_it_reports_what_it_raised(): void
    {
        $this->schedule(['max_occurrences' => 1]);

        $this->artisan('opes:generate-recurring-invoices')
            ->expectsOutputToContain('Raised 1 invoice from 1 schedule.')
            ->assertExitCode(0);
    }

    /**
     * A schedule still behind after the run has to say so. A business whose
     * billing is quietly lagging has no other way to find out.
     */
    public function test_it_warns_when_a_schedule_is_still_behind(): void
    {
        $this->schedule([
            'name' => 'Ancient retainer',
            'starts_on' => now()->subYears(4)->toDateString(),
            'next_run_on' => now()->subYears(4)->toDateString(),
        ]);

        $this->artisan('opes:generate-recurring-invoices')
            ->expectsOutputToContain('hit the catch-up limit')
            ->assertExitCode(0);
    }

    public function test_it_runs_with_no_tenant_in_scope(): void
    {
        $this->schedule(['max_occurrences' => 1]);

        // The scheduler has no current company. Creating a document draws a
        // sync sequence that needs one, so the service must set it per schedule.
        app(CurrentCompany::class)->set(null);

        $this->artisan('opes:generate-recurring-invoices')->assertExitCode(0);

        $this->assertSame(1, RecurringInvoice::withoutGlobalScopes()->first()->occurrences);
    }

    public function test_it_is_registered_on_the_daily_schedule(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('opes:generate-recurring-invoices')
            ->assertExitCode(0);
    }

    public function test_the_catch_up_limit_is_a_real_bound(): void
    {
        $this->assertGreaterThan(0, RecurringInvoices::CATCH_UP_LIMIT);
        $this->assertLessThanOrEqual(24, RecurringInvoices::CATCH_UP_LIMIT, 'an unbounded catch-up is a runaway');
    }
}
