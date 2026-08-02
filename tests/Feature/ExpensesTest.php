<?php

namespace Tests\Feature;

use App\Livewire\Expenses\Index as ExpensesIndex;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\Accounting\Ledger;
use App\Services\ExpenseRecorder;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Money going out, and where it lands in the books.
 *
 * Until this module the ledger only knew about revenue, so a profit figure was
 * built from half the story. These tests are mostly about the other half
 * arriving in the right accounts.
 */
class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

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
            'plan' => 'basic',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);
    }

    protected function recorder(): ExpenseRecorder
    {
        return app(ExpenseRecorder::class);
    }

    protected function lines(Expense|ExpensePayment $source): array
    {
        $entry = app(Ledger::class)->entryFor($this->company, $source);

        return $entry === null ? [] : $entry->load('lines.account')->lines
            ->mapWithKeys(fn ($l) => [$l->account->number => [round((float) $l->debit, 2), round((float) $l->credit, 2)]])
            ->all();
    }

    // ───────────────────────────────────────────────── arithmetic ──

    public function test_tva_is_computed_from_the_amount_before_tax(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Carburant', 'category' => 'fuel',
            'issue_date' => now()->toDateString(), 'amount' => 25000,
            'vat_rate' => 0.1925, 'payment_method' => 'cash',
        ], $this->owner);

        $this->assertSame('25000.00', $expense->amount);
        $this->assertSame('4812.50', $expense->vat_amount);
        $this->assertSame('29812.50', $expense->total);
    }

    public function test_an_expense_with_no_tva_totals_the_net(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Taxi', 'category' => 'transport',
            'issue_date' => now()->toDateString(), 'amount' => 2000,
            'payment_method' => 'cash',
        ], $this->owner);

        $this->assertSame('0.00', $expense->vat_amount);
        $this->assertSame('2000.00', $expense->total);
    }

    // ──────────────────────────────────────────────────── posting ──

    /** Charge the category's account, reclaim the TVA, credit the till. */
    public function test_a_direct_expense_credits_cash_rather_than_payables(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Carburant', 'category' => 'fuel',
            'issue_date' => now()->toDateString(), 'amount' => 25000,
            'vat_rate' => 0.1925, 'payment_method' => 'cash',
        ], $this->owner);

        $lines = $this->lines($expense);

        $this->assertSame([25000.0, 0.0], $lines['6053'], 'Fuel should charge 6053.');
        $this->assertSame([4812.5, 0.0], $lines['445'], 'Deductible TVA should be reclaimed.');
        $this->assertSame([0.0, 29812.5], $lines['571'], 'Paid on the spot, so cash goes down.');
        $this->assertArrayNotHasKey('401', $lines, 'Nothing is owed on a cash purchase.');
    }

    public function test_a_bill_with_terms_credits_payables(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 400000, 'vat_rate' => 0.1925,
        ], $this->owner);

        $lines = $this->lines($expense);

        $this->assertSame([400000.0, 0.0], $lines['601']);
        $this->assertSame([0.0, 477000.0], $lines['401'], 'A bill sits in payables until settled.');
    }

    public function test_settling_a_bill_clears_the_payable(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 400000, 'vat_rate' => 0.1925,
        ], $this->owner);

        $payment = $this->recorder()->settle($expense, [
            'amount' => 200000, 'method' => 'bank', 'paid_on' => now()->toDateString(),
        ], $this->owner);

        $lines = $this->lines($payment);

        $this->assertSame([200000.0, 0.0], $lines['401']);
        $this->assertSame([0.0, 200000.0], $lines['521']);
    }

    /**
     * A cash purchase already credited the till when it was recorded. Posting
     * the settlement as well would take the money out of the books twice.
     */
    public function test_settling_a_cash_expense_does_not_post_a_second_entry(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Carburant', 'category' => 'fuel',
            'issue_date' => now()->toDateString(), 'amount' => 25000,
            'payment_method' => 'cash',
        ], $this->owner);

        $payment = ExpensePayment::query()->firstOrFail();

        $this->assertSame([], $this->lines($payment));
        $this->assertTrue($expense->fresh()->isPaid(), 'It was paid the moment it was recorded.');
    }

    public function test_posting_is_idempotent_per_expense(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'amount' => 100000,
        ], $this->owner);

        $before = JournalEntry::query()->count();

        // The same shape a retry or a replayed sync envelope would take.
        app(Ledger::class)->post(
            company: $this->company, journal: 'AC', entryDate: now()->toDateString(),
            lines: [['account' => 'purchases', 'debit' => 100000], ['account' => 'payables', 'credit' => 100000]],
            source: $expense,
        );

        $this->assertSame($before, JournalEntry::query()->count());
    }

    // ───────────────────────────────────────────────── settlement ──

    public function test_an_expense_cannot_be_overpaid(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'amount' => 100000,
        ], $this->owner);

        $this->expectException(RuntimeException::class);

        $this->recorder()->settle($expense, ['amount' => 100001, 'method' => 'cash'], $this->owner);
    }

    public function test_part_payments_accumulate_and_close_the_balance(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'amount' => 100000,
        ], $this->owner);

        $this->recorder()->settle($expense, ['amount' => 40000, 'method' => 'cash'], $this->owner);
        $this->recorder()->settle($expense, ['amount' => 60000, 'method' => 'bank'], $this->owner);

        $this->assertTrue($expense->fresh()->isPaid());
        $this->assertSame(0.0, $expense->fresh()->balance());
    }

    public function test_an_unpaid_bill_past_its_date_is_overdue(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'amount' => 100000,
        ], $this->owner);

        $this->assertTrue($expense->isOverdue());
    }

    /** A cash purchase has no terms, so it is never chased. */
    public function test_a_direct_expense_is_never_overdue(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Taxi', 'category' => 'transport',
            'issue_date' => now()->subYear()->toDateString(),
            'amount' => 2000, 'payment_method' => 'cash',
        ], $this->owner);

        $this->assertFalse($expense->isOverdue());
    }

    // ────────────────────────────────────────────────────── voids ──

    public function test_voiding_reverses_the_entry_rather_than_deleting_it(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'amount' => 100000,
        ], $this->owner);

        $this->recorder()->void($expense, $this->owner);

        $this->assertSame('void', $expense->fresh()->status);
        // Original plus its reversal: what the books said in March keeps having
        // an answer.
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_an_expense_with_payments_cannot_be_voided(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'amount' => 100000,
        ], $this->owner);

        $this->recorder()->settle($expense, ['amount' => 10000, 'method' => 'cash'], $this->owner);

        $this->expectException(RuntimeException::class);
        $this->recorder()->void($expense, $this->owner);
    }

    // ──────────────────────────────────────────── chart of accounts ──

    public function test_every_expense_category_has_an_account_that_gets_seeded(): void
    {
        $numbers = LedgerAccount::query()->pluck('number')->all();

        foreach (ChartOfAccounts::EXPENSE_CATEGORIES as $category => [$number, $name, $label]) {
            $this->assertContains($number, $numbers, "Category [{$category}] maps to account {$number}, which is not seeded.");
        }
    }

    public function test_an_unknown_category_falls_back_rather_than_losing_the_expense(): void
    {
        $this->assertSame(
            ChartOfAccounts::EXPENSE_CATEGORIES['other'][0],
            ChartOfAccounts::accountForCategory('not-a-category'),
        );
    }

    // ──────────────────────────────────────────────────── the screen ──

    public function test_the_screen_records_an_expense(): void
    {
        Livewire::actingAs($this->owner)
            ->test(ExpensesIndex::class)
            ->call('startRecording')
            ->set('description', 'Airtime for the sales team')
            ->set('category', 'telecoms')
            ->set('amount', '15000')
            ->set('vatRate', '0')
            ->set('paymentMethod', 'mobile_money')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Expense::query()->count());
        $this->assertSame('15000.00', Expense::query()->firstOrFail()->total);
    }

    public function test_the_screen_refuses_a_bill_due_before_it_was_issued(): void
    {
        Livewire::actingAs($this->owner)
            ->test(ExpensesIndex::class)
            ->call('startRecording')
            ->set('description', 'Ciment')
            ->set('amount', '1000')
            ->set('issueDate', now()->toDateString())
            ->set('dueDate', now()->subDay()->toDateString())
            ->call('save')
            ->assertHasErrors('dueDate');
    }

    public function test_the_screen_reports_an_overpayment_as_a_validation_error(): void
    {
        $expense = $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'amount' => 1000,
        ], $this->owner);

        Livewire::actingAs($this->owner)
            ->test(ExpensesIndex::class)
            ->call('startSettling', $expense->id)
            ->set('payAmount', '99999')
            ->call('pay')
            ->assertHasErrors('payAmount');
    }

    // ───────────────────────────────────────────────────── tenancy ──

    public function test_one_company_never_sees_another_companys_expenses(): void
    {
        $this->recorder()->record([
            'description' => 'Mine', 'category' => 'goods',
            'issue_date' => now()->toDateString(), 'amount' => 1000, 'payment_method' => 'cash',
        ], $this->owner);

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)), 'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id, 'currency' => 'XAF', 'plan' => 'basic', 'account_type' => 'active',
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);

        app(CurrentCompany::class)->as($other, function () {
            $this->assertSame(0, Expense::query()->count());
        });

        $this->assertSame(1, Expense::query()->count());
    }

    public function test_a_cashier_may_take_money_in_but_not_record_what_is_spent(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        // Taking payment is their job; deciding what the company spends is not.
        $this->assertTrue($cashier->hasPermissionIn($this->company, 'payments.record'));
        $this->assertFalse($cashier->hasPermissionIn($this->company, 'expenses.create'));

        $this->actingAs($cashier)->get(route('expenses'))->assertForbidden();
    }

    public function test_the_owner_can_reach_the_screen(): void
    {
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($this->owner)->get(route('expenses'))->assertOk();
    }

    // ───────────────────────────────────────────── the whole point ──

    /**
     * The reason this module exists: an income statement that only counted
     * revenue was not an income statement.
     */
    public function test_a_recorded_expense_reaches_the_income_statement(): void
    {
        $this->recorder()->record([
            'description' => 'Ciment', 'category' => 'goods',
            'issue_date' => now()->toDateString(), 'amount' => 400000,
            'payment_method' => 'bank',
        ], $this->owner);

        $statement = app(Books::class)
            ->incomeStatement($this->company, now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString());

        $this->assertGreaterThanOrEqual(400000, $statement['total_charges']);

        // And it lands under the right SYSCOHADA caption, not just in the total.
        $this->assertTrue(
            $statement['charges']->contains(fn ($row) => $row['account']->number === '601'),
            'A purchase of goods should appear against account 601.'
        );
    }
}
