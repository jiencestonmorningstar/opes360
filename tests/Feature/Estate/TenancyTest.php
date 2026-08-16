<?php

namespace Tests\Feature\Estate;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Document;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\RecurringInvoice;
use App\Models\Role;
use App\Models\ServiceTicket;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\Estate\Tenancies;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\Aging;
use App\Support\ContractWatch;
use App\Support\CurrentCompany;
use App\Support\Modules;
use App\Support\OccupancyBoard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * A lease is a Contract, rent is a recurring invoice, a deposit is a posted
 * liability, and a maintenance request is a service ticket. The tests here
 * are mostly about those four sentences staying true — the estate module owns
 * almost nothing, and everything it borrows must keep agreeing with the
 * module it borrowed from.
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Property $property;

    protected PropertyUnit $unit;

    protected Contact $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'immo-'.Str::lower(Str::random(4)),
            'name' => 'Immo Douala Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        // Estate ships off; the business under test switches it on. Service
        // rides along for the maintenance-ticket path.
        $this->company->forceFill(['modules' => ['estate' => true, 'service' => true]])->save();
        Modules::flush();

        app(CurrentCompany::class)->set($this->company);

        // The estate gates are registered by the integrator alongside the
        // routes (see docs/handoff/estate.md). Defined here so these tests
        // exercise the module, not the wiring.
        foreach (['estate.view', 'estate.manage', 'estate.end-tenancy'] as $ability) {
            Gate::define($ability, fn (User $user) => true);
        }

        // The deposit posts through the one ledger, and the ledger needs the
        // bank account the money arrives in.
        ChartOfAccounts::seed($this->company);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Immeuble Bonanjo',
            'kind' => 'residential',
            'created_by' => $this->owner->id,
        ]);

        $this->unit = PropertyUnit::create([
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'label' => 'Apartment 2B',
            'target_rent' => 150000,
            'status' => 'vacant',
        ]);

        $this->tenant = Contact::create(['name' => 'Marie Ngo', 'balance' => 0]);
    }

    protected function start(array $overrides = []): Tenancy
    {
        return app(Tenancies::class)->start($this->unit, array_merge([
            'tenant_contact_id' => $this->tenant->id,
            'rent' => 150000,
            'deposit_amount' => 300000,
            'moved_in_on' => now()->toDateString(),
        ], $overrides), $this->owner);
    }

    /** The account's balance in its own direction, read from journal_lines only. */
    protected function accountBalance(string $number): float
    {
        $account = LedgerAccount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('number', $number)
            ->first();

        if ($account === null) {
            return 0.0;
        }

        $sums = JournalLine::query()
            ->withoutGlobalScopes()
            ->where('ledger_account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')
            ->first();

        return $account->isDebitNormal()
            ? round((float) $sums->debit - (float) $sums->credit, 2)
            : round((float) $sums->credit - (float) $sums->debit, 2);
    }

    protected function unpaidRent(float $amount, int $daysOverdue = 20): Document
    {
        return Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->tenant->id,
            'status' => DocumentStatus::Issued,
            'number' => 'INV-'.Str::upper(Str::random(6)),
            'issue_date' => now()->subDays($daysOverdue + 7)->toDateString(),
            'due_date' => now()->subDays($daysOverdue)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $amount,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'created_by' => $this->owner->id,
        ]);
    }

    // ───────────────────────────────────────────────────────── moving in ──

    public function test_starting_posts_the_deposit_as_a_liability_and_the_books_balance(): void
    {
        $tenancy = $this->start();

        // The caution sits in 165 as the tenant's money, not income — and the
        // bank grew by the same amount, so the trial balance foots.
        $this->assertSame(300000.0, $this->accountBalance('165'));
        $this->assertSame(300000.0, $this->accountBalance('521'));
        $this->assertTrue(app(Books::class)->trialBalance($this->company)['balanced']);

        $this->assertNotNull($tenancy->deposit_entry_id);
        $this->assertSame('occupied', $this->unit->refresh()->status);
    }

    public function test_the_lease_is_a_real_contract_and_appears_in_the_watch(): void
    {
        $tenancy = $this->start([
            'ends_on' => now()->addDays(45)->toDateString(),
            'renewal_type' => 'manual',
        ]);

        $lease = $tenancy->lease;
        $this->assertInstanceOf(Contract::class, $lease);
        $this->assertSame('lease', $lease->type);
        $this->assertTrue($lease->isActive());
        $this->assertSame($this->tenant->id, $lease->contact_id);

        // The watch machinery came free: the lease ends inside the window and
        // the contract module's own list says so — nothing estate-side forked.
        $this->assertTrue(
            (new ContractWatch)->expiring(60)->contains(fn (Contract $c) => $c->id === $lease->id)
        );
    }

    public function test_rent_rides_the_existing_recurring_invoice_path(): void
    {
        $tenancy = $this->start();

        $schedule = $tenancy->rentSchedule;
        $this->assertInstanceOf(RecurringInvoice::class, $schedule);
        $this->assertSame(RecurringInvoice::ACTIVE, $schedule->status);
        $this->assertSame('monthly', $schedule->frequency);
        $this->assertSame(150000.0, (float) $schedule->lines[0]['unit_price']);
        $this->assertTrue($schedule->auto_issue);
    }

    public function test_a_unit_cannot_hold_two_open_tenancies(): void
    {
        $this->start();

        $second = Contact::create(['name' => 'Paul Etame', 'balance' => 0]);

        try {
            app(Tenancies::class)->start($this->unit->refresh(), [
                'tenant_contact_id' => $second->id,
                'rent' => 150000,
            ], $this->owner);
            $this->fail('A sitting tenant should have refused the second letting.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Marie Ngo', $e->getMessage());
        }

        $this->assertSame(1, Tenancy::query()->active()->count());
    }

    public function test_a_tenancy_without_a_deposit_writes_nothing_to_the_books(): void
    {
        $tenancy = $this->start(['deposit_amount' => 0]);

        $this->assertNull($tenancy->deposit_entry_id);
        $this->assertSame(0.0, $this->accountBalance('165'));
    }

    // ──────────────────────────────────────────────────────── moving out ──

    public function test_ending_with_a_retained_deposit_posts_the_retention_as_income_and_refunds_the_rest(): void
    {
        $tenancy = $this->start();

        app(Tenancies::class)->endTenancy($tenancy, [
            'retained' => 80000,
            'retention_reason' => 'Broken window and repaint',
        ], $this->owner);

        // The liability is emptied, the retention became income, and the
        // refund left the bank: 300000 in, 220000 back out.
        $this->assertSame(0.0, $this->accountBalance('165'));
        $this->assertSame(80000.0, $this->accountBalance('7078'));
        $this->assertSame(80000.0, $this->accountBalance('521'));
        $this->assertTrue(app(Books::class)->trialBalance($this->company)['balanced']);

        $tenancy->refresh();
        $this->assertSame('ended', $tenancy->status);
        $this->assertNotNull($tenancy->deposit_settlement_entry_id);
        $this->assertSame(80000.0, (float) $tenancy->deposit_retained);
        $this->assertSame('vacant', $this->unit->refresh()->status);
        $this->assertSame('terminated', $tenancy->lease->refresh()->status);
        $this->assertSame(RecurringInvoice::FINISHED, $tenancy->rentSchedule->refresh()->status);
    }

    public function test_keeping_part_of_the_deposit_needs_a_reason(): void
    {
        $tenancy = $this->start();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/reason/i');

        app(Tenancies::class)->endTenancy($tenancy, ['retained' => 50000], $this->owner);
    }

    public function test_more_cannot_be_retained_than_was_held(): void
    {
        $tenancy = $this->start();

        $this->expectException(RuntimeException::class);

        app(Tenancies::class)->endTenancy($tenancy, [
            'retained' => 400000,
            'retention_reason' => 'Everything',
        ], $this->owner);
    }

    public function test_ending_with_unpaid_rent_refuses_naming_the_balance(): void
    {
        $tenancy = $this->start();
        $this->unpaidRent(150000);

        try {
            app(Tenancies::class)->endTenancy($tenancy, [], $this->owner);
            $this->fail('Unpaid rent should have blocked the move-out.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('150,000', $e->getMessage());
            $this->assertStringContainsString('Marie Ngo', $e->getMessage());
        }

        $this->assertSame('active', $tenancy->refresh()->status);
        $this->assertSame('occupied', $this->unit->refresh()->status);
    }

    public function test_forcing_past_unpaid_rent_needs_a_reason_and_then_works(): void
    {
        $tenancy = $this->start();
        $this->unpaidRent(150000);

        try {
            app(Tenancies::class)->endTenancy($tenancy, ['force' => true], $this->owner);
            $this->fail('Forcing without a reason should refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('reason', mb_strtolower($e->getMessage()));
        }

        $tenancy = app(Tenancies::class)->endTenancy($tenancy->refresh(), [
            'force' => true,
            'force_reason' => 'Tenant deceased; balance written off by the landlord.',
        ], $this->owner);

        $this->assertSame('ended', $tenancy->status);
        $this->assertStringContainsString('deceased', $tenancy->notes);
    }

    public function test_ending_twice_is_refused(): void
    {
        $tenancy = $this->start(['deposit_amount' => 0]);
        app(Tenancies::class)->endTenancy($tenancy, [], $this->owner);

        $this->expectException(RuntimeException::class);

        app(Tenancies::class)->endTenancy($tenancy->refresh(), [], $this->owner);
    }

    // ───────────────────────────────────────────────────────── the board ──

    public function test_the_board_reads_arrears_from_aging_itself(): void
    {
        $tenancy = $this->start();
        $this->unpaidRent(90000, daysOverdue: 45);
        $this->unpaidRent(150000, daysOverdue: 10);

        $board = (new OccupancyBoard)->arrears();

        $this->assertCount(1, $board);
        $this->assertSame($tenancy->id, $board[0]['tenancy']->id);

        // The board's figure IS Aging's figure — same arithmetic, one source.
        $aging = (new Aging)->forParty($this->tenant);
        $this->assertSame($aging['total'], $board[0]['total']);
        $this->assertSame(240000.0, $board[0]['total']);
        $this->assertSame($aging['buckets'], $board[0]['buckets']);
    }

    public function test_the_board_counts_vacancy_and_endings(): void
    {
        PropertyUnit::create([
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'label' => 'Shop 1',
            'target_rent' => 200000,
            'status' => 'vacant',
        ]);

        $this->start(['ends_on' => now()->addDays(30)->toDateString(), 'renewal_type' => 'manual']);

        $summary = (new OccupancyBoard)->summary();

        $this->assertSame(1, $summary['vacant_units']);
        $this->assertSame(200000.0, $summary['target_rent_lost']);
        $this->assertSame(1, $summary['leases_ending']);
        $this->assertSame(1, $summary['open_tenancies']);

        $endings = (new OccupancyBoard)->leasesEnding();
        $this->assertCount(1, $endings);
        $this->assertSame('Apartment 2B', $endings[0]['tenancy']->unit->label);
    }

    // ───────────────────────────────────────────────────────── maintenance ──

    public function test_a_maintenance_request_is_an_ordinary_service_ticket_pinned_to_the_unit(): void
    {
        $tenancy = $this->start();

        $ticket = app(Tenancies::class)->reportMaintenance($tenancy, [
            'subject' => 'Leaking tap in the kitchen',
        ], $this->owner);

        $this->assertInstanceOf(ServiceTicket::class, $ticket);
        $this->assertSame($this->tenant->id, $ticket->contact_id);
        $this->assertSame($this->unit->id, $ticket->property_unit_id);
        $this->assertSame('maintenance', $ticket->category);
        $this->assertStringContainsString('Apartment 2B', $ticket->subject);
        $this->assertSame('new', $ticket->status);
    }

    // ─────────────────────────────────────────────────────────── isolation ──

    public function test_another_company_sees_none_of_it(): void
    {
        $this->start();

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'autre-'.Str::lower(Str::random(4)),
            'name' => 'Autre Immo',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);
        $other->forceFill(['modules' => ['estate' => true]])->save();
        Modules::flush();

        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, Property::query()->count());
        $this->assertSame(0, Tenancy::query()->count());

        $summary = (new OccupancyBoard)->summary();
        $this->assertSame(0, $summary['vacant_units']);
        $this->assertSame(0, $summary['open_tenancies']);
        $this->assertCount(0, (new OccupancyBoard)->arrears());
    }

    public function test_dates_are_calendar_dates_not_midnight_traps(): void
    {
        Carbon::setTestNow('2026-08-16 23:45:00');

        try {
            $tenancy = $this->start();
            $this->assertSame('2026-08-16', $tenancy->moved_in_on->toDateString());

            $ended = app(Tenancies::class)->endTenancy($tenancy, [
                'retained' => 0,
            ], $this->owner);

            $this->assertSame('2026-08-16', $ended->moved_out_on->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }
}
