<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\Role;
use App\Models\SupplierStatement;
use App\Models\SupplierStatementLine;
use App\Models\User;
use App\Services\Payables\SupplierReconciler;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Laying the supplier's statement beside our own record of their account.
 *
 * The load-bearing behaviour is what this refuses to do: it never edits our
 * books to agree with the supplier, and never edits the supplier's statement to
 * agree with our books. Matching records that two records describe one event.
 * Everything left over is the finding.
 */
class SupplierReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $supplier;

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

        $this->supplier = Contact::create(['name' => 'Ciment du Cameroun', 'type' => 'supplier', 'balance' => 0]);
    }

    protected function reconciler(): SupplierReconciler
    {
        return app(SupplierReconciler::class);
    }

    protected function bill(float $total, string $issuedOn, string $reference): Expense
    {
        $expense = new Expense([
            'supplier_id' => $this->supplier->id,
            'reference' => $reference,
            'description' => 'Cement',
            'category' => 'materials',
            'issue_date' => $issuedOn,
            'due_date' => $issuedOn,
            'amount' => $total,
            'vat_rate' => 0,
            'currency' => 'XAF',
            'status' => 'recorded',
            'amount_paid' => 0,
        ]);

        $expense->recompute();
        $expense->save();

        return $expense;
    }

    protected function statement(array $rows, float $closing): SupplierStatement
    {
        return $this->reconciler()->import($this->supplier, [
            'statement_date' => now()->toDateString(),
            'period_from' => now()->subMonth()->toDateString(),
            'period_to' => now()->toDateString(),
            'closing_balance' => $closing,
            'reference' => 'STMT-01',
        ], $rows, $this->owner);
    }

    public function test_a_statement_is_imported_with_its_lines(): void
    {
        $statement = $this->statement([
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
            ['line_date' => now()->subDays(10)->toDateString(), 'reference' => 'RC-9', 'description' => 'Payment received', 'amount' => -200_000],
        ], 300_000);

        $this->assertCount(2, $statement->lines);
        $this->assertSame(300_000.0, (float) $statement->closing_balance);
        $this->assertTrue($statement->selfConsistent());
        $this->assertSame(SupplierStatementLine::STATUS_UNMATCHED, $statement->lines->first()->status);
    }

    public function test_re_importing_the_same_period_does_not_duplicate_lines(): void
    {
        $rows = [
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
        ];

        $statement = $this->statement($rows, 500_000);
        $again = $this->reconciler()->addLines($statement, $rows);

        $this->assertSame(0, $again['imported']);
        $this->assertSame(1, $again['skipped']);
        $this->assertCount(1, $statement->refresh()->lines);
    }

    public function test_a_statement_whose_own_lines_do_not_add_up_is_flagged(): void
    {
        // The supplier's arithmetic, not ours. Worth seeing before anybody
        // spends an afternoon hunting for a bill that was never missing.
        $statement = $this->statement([
            ['line_date' => now()->subDays(5)->toDateString(), 'reference' => 'FA-1', 'description' => 'Invoice', 'amount' => 100_000],
        ], 250_000);

        $this->assertFalse($statement->selfConsistent());
        $this->assertFalse($this->reconciler()->summary($statement)['statement_self_consistent']);
    }

    public function test_lines_are_suggested_a_match_by_reference_and_amount(): void
    {
        $bill = $this->bill(500_000, now()->subDays(20)->toDateString(), 'FA-101');
        $this->bill(500_000, now()->subDays(19)->toDateString(), 'FA-102');

        $statement = $this->statement([
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
        ], 500_000);

        $suggestions = $this->reconciler()->suggestionsFor($statement->lines->first());

        // Same amount on both, so the reference is what decides — and it must
        // decide, or the matcher offers a coin toss between two real bills.
        $this->assertSame($bill->id, $suggestions->first()->id);
    }

    public function test_matching_pairs_a_line_with_a_bill_without_changing_either(): void
    {
        $bill = $this->bill(500_000, now()->subDays(20)->toDateString(), 'FA-101');

        $statement = $this->statement([
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
        ], 500_000);

        $line = $this->reconciler()->match($statement->lines->first(), $bill);

        $this->assertSame(SupplierStatementLine::STATUS_MATCHED, $line->status);
        $this->assertSame($bill->id, $line->expense_id);
        $this->assertNotNull($line->matched_at);

        // The bill is untouched. The moment matching starts correcting one side
        // it can no longer tell you the two disagreed, which was the point.
        $this->assertSame(500_000.0, (float) $bill->refresh()->total);
        $this->assertSame(0.0, (float) $bill->amount_paid);
    }

    public function test_one_bill_cannot_be_matched_to_two_statement_lines(): void
    {
        $bill = $this->bill(500_000, now()->subDays(20)->toDateString(), 'FA-101');

        $statement = $this->statement([
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101-BIS', 'description' => 'Invoice again', 'amount' => 500_000],
        ], 1_000_000);

        $this->reconciler()->match($statement->lines[0], $bill);

        // A supplier double-billing us is exactly the finding this exists for.
        // Letting both lines point at one bill would hide it.
        $this->expectException(RuntimeException::class);
        $this->reconciler()->match($statement->lines[1], $bill);
    }

    public function test_auto_matching_pairs_only_the_unambiguous_lines(): void
    {
        $this->bill(500_000, now()->subDays(20)->toDateString(), 'FA-101');
        $this->bill(300_000, now()->subDays(15)->toDateString(), 'FA-102');

        $statement = $this->statement([
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
            ['line_date' => now()->subDays(15)->toDateString(), 'reference' => 'FA-102', 'description' => 'Invoice', 'amount' => 999_999],
            ['line_date' => now()->subDays(2)->toDateString(), 'reference' => 'FA-999', 'description' => 'Invoice', 'amount' => 40_000],
        ], 1_539_999);

        $matched = $this->reconciler()->autoMatch($statement);

        // Only the line that agrees on both reference and amount. FA-102 has
        // the right reference and the wrong money, which is a dispute, not a
        // match — and silently matching it would bury a 700,000 overcharge.
        $this->assertSame(1, $matched);
        $this->assertSame(SupplierStatementLine::STATUS_UNMATCHED, $statement->refresh()->lines[1]->status);
    }

    public function test_the_summary_shows_the_difference_and_what_explains_it(): void
    {
        $this->bill(500_000, now()->subDays(20)->toDateString(), 'FA-101');
        // A bill we hold that never appeared on their statement.
        $this->bill(75_000, now()->subDays(8)->toDateString(), 'FA-777');

        $statement = $this->statement([
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
            // A charge they claim and we have never seen.
            ['line_date' => now()->subDays(4)->toDateString(), 'reference' => 'FA-555', 'description' => 'Delivery', 'amount' => 120_000],
        ], 620_000);

        $this->reconciler()->autoMatch($statement);

        $summary = $this->reconciler()->summary($statement->refresh());

        $this->assertSame(620_000.0, $summary['their_balance']);
        $this->assertSame(575_000.0, $summary['our_balance']);
        $this->assertSame(45_000.0, $summary['difference']);
        $this->assertFalse($summary['reconciled']);

        $this->assertSame(['FA-555'], collect($summary['on_their_statement_only'])->pluck('reference')->all());
        $this->assertSame(['FA-777'], collect($summary['in_our_books_only'])->pluck('reference')->all());
    }

    public function test_a_fully_matched_statement_that_agrees_reads_as_reconciled(): void
    {
        $this->bill(500_000, now()->subDays(20)->toDateString(), 'FA-101');

        $statement = $this->statement([
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
        ], 500_000);

        $this->reconciler()->autoMatch($statement);
        $summary = $this->reconciler()->summary($statement->refresh());

        $this->assertSame(0.0, $summary['difference']);
        $this->assertTrue($summary['reconciled']);
        $this->assertSame(SupplierStatement::STATUS_RECONCILED, $statement->refresh()->status);
    }

    public function test_a_disputed_line_is_kept_visible_rather_than_matched_away(): void
    {
        $statement = $this->statement([
            ['line_date' => now()->subDays(4)->toDateString(), 'reference' => 'FA-555', 'description' => 'Delivery', 'amount' => 120_000],
        ], 120_000);

        $line = $this->reconciler()->dispute($statement->lines->first(), 'Never delivered');

        $this->assertSame(SupplierStatementLine::STATUS_DISPUTED, $line->status);
        $this->assertSame('Never delivered', $line->note);

        $summary = $this->reconciler()->summary($statement->refresh());

        $this->assertSame(120_000.0, $summary['disputed_total']);
        $this->assertSame(SupplierStatement::STATUS_DISPUTED, $statement->refresh()->status);
    }

    public function test_unmatching_returns_a_line_to_the_pile(): void
    {
        $bill = $this->bill(500_000, now()->subDays(20)->toDateString(), 'FA-101');

        $statement = $this->statement([
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
        ], 500_000);

        $line = $this->reconciler()->match($statement->lines->first(), $bill);
        $line = $this->reconciler()->unmatch($line);

        $this->assertSame(SupplierStatementLine::STATUS_UNMATCHED, $line->status);
        $this->assertNull($line->expense_id);
        $this->assertNull($line->matched_at);
    }

    public function test_a_bill_from_another_supplier_cannot_be_matched(): void
    {
        $other = Contact::create(['name' => 'Autre Sarl', 'type' => 'supplier', 'balance' => 0]);

        $foreign = new Expense([
            'supplier_id' => $other->id,
            'reference' => 'FA-101',
            'description' => 'Cement',
            'category' => 'materials',
            'issue_date' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(20)->toDateString(),
            'amount' => 500_000,
            'vat_rate' => 0,
            'currency' => 'XAF',
            'status' => 'recorded',
            'amount_paid' => 0,
        ]);
        $foreign->recompute();
        $foreign->save();

        $statement = $this->statement([
            ['line_date' => now()->subDays(20)->toDateString(), 'reference' => 'FA-101', 'description' => 'Invoice', 'amount' => 500_000],
        ], 500_000);

        $this->expectException(RuntimeException::class);
        $this->reconciler()->match($statement->lines->first(), $foreign);
    }

    public function test_a_suppliers_csv_is_parsed_into_lines(): void
    {
        $csv = "Date,Reference,Details,Debit,Credit\n"
            ."2026-08-01,FA-101,Cement delivery,500000,\n"
            ."2026-08-15,RC-9,Payment,,200000\n";

        $rows = $this->reconciler()->parseCsv($csv);

        $this->assertCount(2, $rows);
        $this->assertSame(500000.0, $rows[0]['amount']);
        // Debit/credit folded into one signed figure: a payment they credited
        // us reduces what they say we owe.
        $this->assertSame(-200000.0, $rows[1]['amount']);
        $this->assertSame('FA-101', $rows[0]['reference']);
    }
}
