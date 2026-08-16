<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountTransfer;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FiscalPeriods;
use App\Services\Banking\AccountTransfers;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AccountTransferTest extends TestCase
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

    public function test_a_transfer_moves_money_between_two_accounts(): void
    {
        $transfer = $this->transfer(50_000);

        $this->assertSame('50000.00', $transfer->amount);
        $this->assertTrue($transfer->fromAccount->is($this->bank()));
        $this->assertTrue($transfer->toAccount->is($this->cash()));
    }

    public function test_a_transfer_posts_a_balanced_entry_to_the_books(): void
    {
        $transfer = $this->transfer(50_000);

        $entry = JournalEntry::query()
            ->where('source_type', $transfer->getMorphClass())
            ->where('source_id', $transfer->id)
            ->with('lines')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(50_000.0, $entry->totalDebit());
        $this->assertSame('BQ', $entry->journal);
    }

    public function test_the_receiving_account_is_debited_and_the_sending_one_credited(): void
    {
        $transfer = $this->transfer(50_000);

        $entry = JournalEntry::query()
            ->where('source_id', $transfer->id)->with('lines')->first();

        $toLine = $entry->lines->firstWhere('ledger_account_id', $this->cash()->id);
        $fromLine = $entry->lines->firstWhere('ledger_account_id', $this->bank()->id);

        $this->assertSame('50000.00', $toLine->debit);
        $this->assertSame('50000.00', $fromLine->credit);
    }

    public function test_a_zero_or_negative_transfer_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->transfer(0);
    }

    public function test_transferring_an_account_to_itself_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(AccountTransfers::class)->record(
            $this->company, $this->bank(), $this->bank(), 1000, '2026-03-10', $this->owner,
        );
    }

    /**
     * A transfer IS the bookkeeping — unlike a sale, there is no
     * counter-side event that happened regardless. A silent posting failure
     * would be money that left one account and arrived nowhere.
     */
    public function test_a_transfer_into_a_closed_period_fails_loudly_rather_than_silently(): void
    {
        $year = app(FiscalPeriods::class)->createYearWithMonths(
            $this->company, '2026', CarbonImmutable::parse('2026-01-01'), $this->owner,
        );
        app(FiscalPeriods::class)->closePeriod(
            $year->periods()->where('name', 'March 2026')->first(), $this->owner,
        );

        $this->expectException(RuntimeException::class);

        $this->transfer(50_000, '2026-03-10');
    }

    public function test_nothing_is_recorded_when_the_posting_is_refused(): void
    {
        $year = app(FiscalPeriods::class)->createYearWithMonths(
            $this->company, '2026', CarbonImmutable::parse('2026-01-01'), $this->owner,
        );
        app(FiscalPeriods::class)->closePeriod(
            $year->periods()->where('name', 'March 2026')->first(), $this->owner,
        );

        try {
            $this->transfer(50_000, '2026-03-10');
        } catch (RuntimeException) {
            // Expected — the transaction must have rolled the transfer back too.
        }

        $this->assertDatabaseCount('account_transfers', 0);
    }

    protected function transfer(float $amount, string $on = '2026-04-10'): AccountTransfer
    {
        return app(AccountTransfers::class)->record(
            $this->company, $this->bank(), $this->cash(), $amount, $on, $this->owner,
            'TRF-1', 'Float for the till',
        );
    }

    protected function bank(): LedgerAccount
    {
        return ChartOfAccounts::account($this->company, 'bank');
    }

    protected function cash(): LedgerAccount
    {
        return ChartOfAccounts::account($this->company, 'cash');
    }
}
