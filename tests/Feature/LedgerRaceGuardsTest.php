<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Banking\Reconciler;
use App\Services\ExpenseRecorder;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Replay and race guards around the ledger.
 *
 * A true two-connection race cannot be reproduced inside one sqlite test
 * transaction, so these tests assert the guards themselves: the unique index
 * that makes a concurrent double-insert impossible, and the replay behaviour
 * (find-or-refuse, never double-post) that the lock-before-check pattern
 * produces single-threaded.
 */
class LedgerRaceGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->user->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->user, Role::OWNER);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);

        $this->contact = Contact::create(['name' => 'Ndongo Ltd']);
    }

    protected function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    protected function postSourced(): JournalEntry
    {
        return $this->ledger()->post(
            $this->company,
            'OD',
            now()->toDateString(),
            [
                ['account' => 'bank', 'debit' => 1000],
                ['account' => 'receivables', 'credit' => 1000],
            ],
            source: $this->contact,
        );
    }

    // ───────────────────────────────────── idempotency per source ──

    public function test_the_database_refuses_a_second_entry_for_the_same_source(): void
    {
        $this->postSourced();

        // The unique index on (company_id, source_type, source_id) is the
        // defence a concurrent replay cannot slip past — the racy SELECT can.
        $this->expectException(QueryException::class);

        JournalEntry::create([
            'company_id' => $this->company->id,
            'journal' => 'OD',
            'entry_date' => now()->toDateString(),
            'source_type' => Contact::class,
            'source_id' => $this->contact->id,
        ]);
    }

    public function test_a_replayed_post_returns_the_original_entry(): void
    {
        $first = $this->postSourced();
        $second = $this->postSourced();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    // ─────────────────────────────────────────── double reversal ──

    public function test_reversing_twice_returns_the_original_reversal(): void
    {
        $entry = $this->postSourced();

        $first = $this->ledger()->reverse($entry, $this->user);
        $second = $this->ledger()->reverse($entry, $this->user);

        $this->assertSame($first->id, $second->id);
        // One original, one reversal — never two reversals.
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_a_replayed_expense_void_does_not_reverse_the_books_twice(): void
    {
        $expense = app(ExpenseRecorder::class)->record([
            'description' => 'Carburant', 'category' => 'fuel',
            'issue_date' => now()->toDateString(), 'amount' => 25000,
        ], $this->user);

        // The stale model a retried job or a second browser tab holds.
        $stale = $expense->fresh();

        app(ExpenseRecorder::class)->void($expense, $this->user);

        try {
            app(ExpenseRecorder::class)->void($stale, $this->user);
        } catch (RuntimeException) {
            // Refusing the replay is fine; doubling the reversal is not.
        }

        $this->assertSame(
            1,
            JournalEntry::query()->whereNotNull('reverses_entry_id')->count(),
            'A replayed void must never post a second reversal.'
        );
    }

    // ─────────────────────────────────────────────── reconciler ──

    public function test_a_failed_posting_never_marks_the_statement_line_matched(): void
    {
        $account = BankAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Compte courant',
            'bank_name' => 'UBA',
            'currency' => 'XAF',
            'ledger_account_id' => LedgerAccount::query()->where('number', '521')->value('id'),
            'is_default' => true,
            'active' => true,
        ]);

        app(Reconciler::class)->import($account, [[
            'value_date' => now()->toDateString(),
            'description' => 'Frais de tenue de compte',
            'amount' => -5000,
        ]], $this->user);

        $line = BankStatementLine::query()->firstOrFail();

        $this->mock(Ledger::class, function ($mock) {
            $mock->shouldReceive('post')->andThrow(new RuntimeException('posting failed'));
        });

        try {
            app(Reconciler::class)->recordFromStatement($line, '631', $this->user);
            $this->fail('A swallowed posting failure must propagate.');
        } catch (RuntimeException) {
            // Expected: the failure surfaces instead of vanishing.
        }

        $line->refresh();
        $this->assertSame('unmatched', $line->status, 'The line must stay available for matching.');
        $this->assertNull($line->journal_entry_id);
    }
}
