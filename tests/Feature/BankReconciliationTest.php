<?php

namespace Tests\Feature;

use App\Livewire\Banking\Index as BankingIndex;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Banking\Reconciler;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Matching the statement against the books.
 *
 * The load-bearing behaviour is what the module refuses to do: it never edits
 * the books to agree with the bank, and never edits the bank to agree with the
 * books. Matching records that two records describe one event; everything else
 * stays exactly as it was.
 */
class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);

        $this->account = BankAccount::create([
            'company_id' => $this->company->id,
            'name' => 'Compte courant',
            'bank_name' => 'UBA',
            'currency' => 'XAF',
            'ledger_account_id' => LedgerAccount::query()->where('number', '521')->value('id'),
            'is_default' => true,
            'active' => true,
        ]);
    }

    protected function reconciler(): Reconciler
    {
        return app(Reconciler::class);
    }

    /** A movement in the books: money into the bank from a customer. */
    protected function bookReceipt(float $amount, string $on): void
    {
        app(Ledger::class)->post(
            company: $this->company,
            journal: 'BQ',
            entryDate: $on,
            lines: [
                ['account' => 'bank', 'debit' => $amount],
                ['account' => 'receivables', 'credit' => $amount],
            ],
            narration: 'Règlement client',
        );
    }

    protected function statementLine(float $amount, string $on, string $description = 'Virement'): BankStatementLine
    {
        $this->reconciler()->import($this->account, [[
            'value_date' => $on,
            'description' => $description,
            'amount' => $amount,
        ]], $this->owner);

        // By description, not by "latest": two lines created in the same second
        // have no reliable order, and the wrong one silently passes tests that
        // are about telling them apart.
        return BankStatementLine::query()
            ->where('description', $description)
            ->where('amount', $amount)
            ->firstOrFail();
    }

    // ─────────────────────────────────────────────────────── importing ──

    public function test_a_csv_is_parsed_into_lines(): void
    {
        $rows = $this->reconciler()->parseCsv(<<<'CSV'
        Date,Libellé,Montant,Solde
        03/08/2026,Virement SARL Etoa,"1 250 000,00","4 100 000,00"
        05/08/2026,Frais de tenue de compte,"-5 000,00","4 095 000,00"
        CSV);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-08-03', $rows[0]['value_date']);
        $this->assertSame(1250000.0, $rows[0]['amount']);
        $this->assertSame(-5000.0, $rows[1]['amount'], 'Money out stays negative.');
    }

    /** Banks export debit and credit as separate columns as often as not. */
    public function test_a_debit_and_credit_pair_folds_into_one_signed_amount(): void
    {
        $rows = $this->reconciler()->parseCsv(<<<'CSV'
        Date,Description,Debit,Credit
        2026-08-03,Depot especes,,250000
        2026-08-04,Retrait,80000,
        CSV);

        $this->assertSame(250000.0, $rows[0]['amount']);
        $this->assertSame(-80000.0, $rows[1]['amount']);
    }

    public function test_a_file_without_a_date_or_an_amount_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('date column');

        $this->reconciler()->parseCsv("Something,Else\nfoo,bar");
    }

    /**
     * Banks rarely let you export "everything since last time", so overlapping
     * imports are the normal case rather than an accident.
     */
    public function test_reimporting_an_overlapping_period_skips_what_is_already_there(): void
    {
        $rows = [
            ['value_date' => '2026-08-03', 'description' => 'Virement', 'amount' => 100000],
            ['value_date' => '2026-08-04', 'description' => 'Frais', 'amount' => -5000],
        ];

        $this->reconciler()->import($this->account, $rows, $this->owner);

        $second = $this->reconciler()->import($this->account, array_merge($rows, [
            ['value_date' => '2026-08-05', 'description' => 'Virement', 'amount' => 42000],
        ]), $this->owner);

        $this->assertSame(1, $second['imported']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(3, BankStatementLine::query()->count());
    }

    /** Two genuinely identical movements on one day are two movements. */
    public function test_a_repeated_import_within_one_file_is_not_deduplicated_away(): void
    {
        $result = $this->reconciler()->import($this->account, [
            ['value_date' => '2026-08-03', 'description' => 'Virement', 'amount' => 100000, 'reference' => 'A1'],
            ['value_date' => '2026-08-03', 'description' => 'Virement', 'amount' => 100000, 'reference' => 'A2'],
        ], $this->owner);

        $this->assertSame(2, $result['imported']);
    }

    // ──────────────────────────────────────────────────────── matching ──

    public function test_a_statement_line_is_suggested_an_entry_of_the_same_amount(): void
    {
        $this->bookReceipt(1250000, '2026-08-03');
        $line = $this->statementLine(1250000, '2026-08-05');

        $suggestions = $this->reconciler()->suggestionsFor($line);

        $this->assertCount(1, $suggestions);
    }

    /** Same amount, wrong direction, is two unrelated movements. */
    public function test_an_entry_on_the_wrong_side_is_not_suggested(): void
    {
        $this->bookReceipt(1250000, '2026-08-03');
        $line = $this->statementLine(-1250000, '2026-08-03');

        $this->assertCount(0, $this->reconciler()->suggestionsFor($line));
    }

    public function test_an_entry_too_far_away_in_time_is_not_suggested(): void
    {
        $this->bookReceipt(1250000, '2026-06-01');
        $line = $this->statementLine(1250000, '2026-08-05');

        $this->assertCount(0, $this->reconciler()->suggestionsFor($line));
    }

    /**
     * The distinction the module exists for: matching says the two records
     * describe one event. It rewrites neither.
     */
    public function test_matching_changes_nothing_but_the_match(): void
    {
        $this->bookReceipt(1250000, '2026-08-03');
        $line = $this->statementLine(1250000, '2026-08-03');

        $book = $this->reconciler()->suggestionsFor($line)->firstOrFail();
        $bookAmountBefore = (float) $book->debit;

        $this->reconciler()->match($line, $book, $this->owner);

        $line->refresh();

        $this->assertTrue($line->isMatched());
        $this->assertSame($book->journal_entry_id, $line->journal_entry_id);
        $this->assertSame(1250000.0, $line->absoluteAmount(), 'The statement is untouched.');
        $this->assertSame($bookAmountBefore, (float) $book->fresh()->debit, 'And so are the books.');
    }

    public function test_a_matched_line_cannot_be_matched_again(): void
    {
        $this->bookReceipt(1250000, '2026-08-03');
        $line = $this->statementLine(1250000, '2026-08-03');
        $book = $this->reconciler()->suggestionsFor($line)->firstOrFail();

        $this->reconciler()->match($line, $book, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been matched');

        $this->reconciler()->match($line->fresh(), $book, $this->owner);
    }

    /** One entry cannot answer for two different statement lines. */
    public function test_one_entry_cannot_be_matched_to_two_lines(): void
    {
        $this->bookReceipt(100000, '2026-08-03');
        $first = $this->statementLine(100000, '2026-08-03', 'Virement A');
        $second = $this->statementLine(100000, '2026-08-03', 'Virement B');

        $book = $this->reconciler()->suggestionsFor($first)->firstOrFail();
        $this->reconciler()->match($first, $book, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already matched to that entry');

        $this->reconciler()->match($second, $book, $this->owner);
    }

    public function test_a_match_can_be_undone(): void
    {
        $this->bookReceipt(100000, '2026-08-03');
        $line = $this->statementLine(100000, '2026-08-03');
        $book = $this->reconciler()->suggestionsFor($line)->firstOrFail();

        $this->reconciler()->match($line, $book, $this->owner);
        $this->reconciler()->unmatch($line->fresh());

        $this->assertFalse($line->fresh()->isMatched());
        $this->assertCount(1, $this->reconciler()->suggestionsFor($line->fresh()), 'The entry is free again.');
    }

    // ────────────────────────────────────── recording what nobody saw ──

    /**
     * The useful half. A bank charge reaches the books only this way, because
     * nobody in the business ever sees it happen.
     */
    public function test_an_unmatched_line_can_be_written_into_the_books(): void
    {
        $line = $this->statementLine(-5000, '2026-08-04', 'Frais de tenue de compte');

        $this->reconciler()->recordFromStatement($line, '631', $this->owner);

        $line->refresh();

        $this->assertTrue($line->isMatched());
        $this->assertNotNull($line->journal_entry_id);

        $lines = $line->entry->load('lines.account')->lines
            ->mapWithKeys(fn ($l) => [$l->account->number => [(float) $l->debit, (float) $l->credit]])->all();

        $this->assertSame([5000.0, 0.0], $lines['631'], 'The charge is a cost.');
        $this->assertSame([0.0, 5000.0], $lines['521'], 'And the bank balance goes down.');
    }

    public function test_money_arriving_is_written_the_other_way_round(): void
    {
        $line = $this->statementLine(12000, '2026-08-04', 'Intérêts créditeurs');

        $this->reconciler()->recordFromStatement($line, '771', $this->owner);

        $lines = $line->fresh()->entry->load('lines.account')->lines
            ->mapWithKeys(fn ($l) => [$l->account->number => [(float) $l->debit, (float) $l->credit]])->all();

        $this->assertSame([12000.0, 0.0], $lines['521']);
        $this->assertSame([0.0, 12000.0], $lines['771']);
    }

    public function test_an_unknown_account_number_is_refused(): void
    {
        $line = $this->statementLine(-5000, '2026-08-04');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no account numbered');

        $this->reconciler()->recordFromStatement($line, '99999', $this->owner);
    }

    // ─────────────────────────────────────────────────── the arithmetic ──

    /**
     * The summary is arithmetic, not a verdict: the difference is what remains
     * unexplained once every unmatched movement on both sides is accounted for.
     */
    public function test_everything_matched_and_the_two_balances_agree(): void
    {
        $this->bookReceipt(1250000, '2026-08-03');
        $line = $this->statementLine(1250000, '2026-08-03');
        $this->reconciler()->match($line, $this->reconciler()->suggestionsFor($line)->firstOrFail(), $this->owner);

        $this->account->forceFill([
            'statement_balance' => 1250000,
            'statement_date' => '2026-08-31',
        ])->save();

        $summary = $this->reconciler()->summary($this->account->fresh());

        $this->assertSame(1250000.0, $summary['book_balance']);
        $this->assertSame(0.0, $summary['difference']);
        $this->assertTrue($summary['reconciled']);
    }

    /**
     * An uncleared cheque: the books know, the bank does not yet. It explains
     * the gap rather than being an error, so the month still reconciles — but
     * the figure stays visible instead of being quietly absorbed.
     */
    public function test_a_movement_the_bank_has_not_shown_explains_the_gap(): void
    {
        $this->bookReceipt(1250000, '2026-08-03');

        $this->account->forceFill([
            'statement_balance' => 0,
            'statement_date' => '2026-08-31',
        ])->save();

        $summary = $this->reconciler()->summary($this->account->fresh());

        $this->assertSame(1250000.0, $summary['unmatched_book']);
        $this->assertSame(0.0, $summary['difference'], 'Explained, so nothing is left over.');
        $this->assertTrue($summary['reconciled']);
    }

    /** A charge on the statement nobody has recorded. */
    public function test_a_movement_the_books_do_not_know_explains_the_gap_too(): void
    {
        $this->statementLine(-5000, '2026-08-04', 'Frais');

        $this->account->forceFill([
            'statement_balance' => -5000,
            'statement_date' => '2026-08-31',
        ])->save();

        $summary = $this->reconciler()->summary($this->account->fresh());

        $this->assertSame(5000.0, $summary['unmatched_out']);
        $this->assertSame(0.0, $summary['difference']);
        $this->assertSame(1, $summary['unmatched_count']);
    }

    /**
     * The failure that would make the module unusable in practice: a business
     * three years into trading has three years of movements in the books, none
     * of which will ever appear on this month's statement. Without a line
     * drawn under them, the arithmetic insists the bank owes it three years of
     * history and no month can ever reconcile.
     */
    public function test_a_business_with_history_can_still_reconcile_this_month(): void
    {
        // Three years of trading, all long settled.
        $this->bookReceipt(30000000, '2023-01-15');
        $this->bookReceipt(12000000, '2024-06-02');

        // This month: one movement, and one statement line for it.
        $this->bookReceipt(500000, '2026-08-10');
        $line = $this->statementLine(500000, '2026-08-10');
        $this->reconciler()->match($line, $this->reconciler()->suggestionsFor($line)->firstOrFail(), $this->owner);

        $this->account->forceFill([
            'opening_balance' => 42000000,
            'opened_on' => '2026-07-31',
            'statement_balance' => 42500000,
            'statement_date' => '2026-08-31',
        ])->save();

        $summary = $this->reconciler()->summary($this->account->fresh());

        $this->assertSame(42500000.0, $summary['book_balance'], 'Opening plus this period’s movements.');
        $this->assertSame(0.0, $summary['unmatched_book'], 'The history was settled when the line was drawn.');
        $this->assertSame(0.0, $summary['difference']);
        $this->assertTrue($summary['reconciled']);
    }

    public function test_a_statement_line_before_the_starting_point_is_left_out(): void
    {
        $this->statementLine(-9999, '2026-06-01', 'Vieux frais');

        $this->account->forceFill([
            'opening_balance' => 0,
            'opened_on' => '2026-07-31',
            'statement_balance' => 0,
            'statement_date' => '2026-08-31',
        ])->save();

        $summary = $this->reconciler()->summary($this->account->fresh());

        $this->assertSame(0, $summary['unmatched_count']);
        $this->assertTrue($summary['reconciled']);
    }

    /** Nothing explains this one, which is exactly when somebody should look. */
    public function test_a_genuine_discrepancy_shows_up_as_unexplained(): void
    {
        $this->bookReceipt(1000000, '2026-08-03');
        $line = $this->statementLine(1000000, '2026-08-03');
        $this->reconciler()->match($line, $this->reconciler()->suggestionsFor($line)->firstOrFail(), $this->owner);

        $this->account->forceFill([
            'statement_balance' => 940000,
            'statement_date' => '2026-08-31',
        ])->save();

        $summary = $this->reconciler()->summary($this->account->fresh());

        $this->assertSame(-60000.0, $summary['difference']);
        $this->assertFalse($summary['reconciled']);
    }

    // ────────────────────────────────────────────────────────── screen ──

    public function test_the_screen_imports_a_line_and_records_it(): void
    {
        $screen = Livewire::actingAs($this->owner)
            ->test(BankingIndex::class)
            ->set('accountId', $this->account->id)
            ->call('startAddingLine')
            ->set('lineDate', '2026-08-04')
            ->set('lineDescription', 'Frais de tenue de compte')
            ->set('lineAmount', '-5000')
            ->call('saveLine')
            ->assertHasNoErrors();

        $line = BankStatementLine::query()->firstOrFail();

        $screen->call('startRecording', $line->id)
            ->set('counterAccount', '631')
            ->call('recordIntoBooks')
            ->assertHasNoErrors();

        $this->assertTrue($line->fresh()->isMatched());
    }

    public function test_a_zero_movement_is_refused(): void
    {
        Livewire::actingAs($this->owner)
            ->test(BankingIndex::class)
            ->set('accountId', $this->account->id)
            ->call('startAddingLine')
            ->set('lineDescription', 'Rien')
            ->set('lineAmount', '0')
            ->call('saveLine')
            ->assertHasErrors('lineAmount');
    }

    public function test_a_sales_officer_cannot_reach_the_reconciliation(): void
    {
        $seller = User::factory()->create();
        $this->joinCompany($this->company, $seller, 'sales-officer');

        $this->actingAs($seller)->get(route('banking'))->assertForbidden();
    }

    public function test_the_reconciliation_belongs_to_its_company_alone(): void
    {
        $this->statementLine(-5000, '2026-08-04');

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl', 'owner_id' => $otherOwner->id,
            'currency' => 'XAF', 'plan' => 'business', 'account_type' => 'active',
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);
        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, BankAccount::query()->count());
        $this->assertSame(0, BankStatementLine::query()->count());
    }
}
