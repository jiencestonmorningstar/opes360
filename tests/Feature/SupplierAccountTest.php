<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\SupplierAccount;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Our record of one supplier's account over a period.
 *
 * The mirror of the customer statement, and the document a supplier's own
 * statement is argued against. The thing to prove is the arithmetic: bills up,
 * payments down, and an opening balance that does not quietly disappear.
 */
class SupplierAccountTest extends TestCase
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

        $this->supplier = Contact::create(['name' => 'Sable du Wouri', 'type' => 'supplier', 'balance' => 0]);
    }

    protected function bill(float $total, string $issuedOn, string $reference, ?string $dueOn = null): Expense
    {
        $expense = new Expense([
            'supplier_id' => $this->supplier->id,
            'reference' => $reference,
            'description' => 'Sand',
            'category' => 'materials',
            'issue_date' => $issuedOn,
            'due_date' => $dueOn ?? $issuedOn,
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

    protected function pay(Expense $expense, float $amount, string $on): ExpensePayment
    {
        $payment = ExpensePayment::create([
            'expense_id' => $expense->id,
            'amount' => $amount,
            'currency' => 'XAF',
            'method' => 'bank',
            'paid_on' => $on,
        ]);

        $expense->forceFill(['amount_paid' => round((float) $expense->amount_paid + $amount, 2)])->save();

        return $payment;
    }

    public function test_the_account_lists_bills_and_payments_with_a_running_balance(): void
    {
        $bill = $this->bill(900_000, now()->subDays(20)->toDateString(), 'SUP-1');
        $this->pay($bill, 400_000, now()->subDays(10)->toDateString());

        $account = (new SupplierAccount($this->supplier, now()->subMonth(), now()))->build();

        $this->assertSame(0.0, $account['opening_balance']);
        $this->assertCount(2, $account['lines']);
        $this->assertSame(900_000.0, $account['lines'][0]['credit']);
        $this->assertSame(400_000.0, $account['lines'][1]['debit']);
        $this->assertSame(500_000.0, $account['closing_balance']);
        $this->assertSame(900_000.0, $account['totals']['billed']);
        $this->assertSame(400_000.0, $account['totals']['paid']);
    }

    public function test_everything_before_the_period_is_carried_in_as_an_opening_balance(): void
    {
        // A bill from three months ago, never paid. A statement for last month
        // that starts at zero reads as a claim for last month alone, and that
        // is what would get argued about.
        $this->bill(300_000, now()->subMonths(3)->toDateString(), 'OLD-1');
        $this->bill(100_000, now()->subDays(5)->toDateString(), 'NEW-1');

        $account = (new SupplierAccount($this->supplier, now()->subMonth(), now()))->build();

        $this->assertSame(300_000.0, $account['opening_balance']);
        $this->assertCount(1, $account['lines']);
        $this->assertSame(400_000.0, $account['closing_balance']);
    }

    public function test_a_voided_bill_leaves_the_account(): void
    {
        $bill = $this->bill(50_000, now()->subDays(3)->toDateString(), 'VOID-1');
        $bill->forceFill(['status' => 'void'])->save();

        $account = (new SupplierAccount($this->supplier, now()->subMonth(), now()))->build();

        $this->assertCount(0, $account['lines']);
        $this->assertSame(0.0, $account['closing_balance']);
    }

    public function test_the_account_ages_what_is_still_open_at_the_end_of_the_period(): void
    {
        $this->bill(200_000, now()->subDays(100)->toDateString(), 'AGED-1', now()->subDays(95)->toDateString());

        $account = (new SupplierAccount($this->supplier, now()->subMonths(6), now()))->build();

        $this->assertSame(200_000.0, $account['aging']['over_90']);
        $this->assertCount(1, $account['open_bills']);
        $this->assertSame('over_90', $account['open_bills'][0]['bucket']);
    }

    public function test_a_past_period_is_still_right_about_that_period(): void
    {
        $bill = $this->bill(80_000, now()->subDays(60)->toDateString(), 'PAST-1');
        // Paid after the period being reported on. The period must not know.
        $this->pay($bill, 80_000, now()->subDays(5)->toDateString());

        $account = (new SupplierAccount(
            $this->supplier,
            Carbon::now()->subDays(70),
            Carbon::now()->subDays(40),
        ))->build();

        $this->assertSame(80_000.0, $account['closing_balance']);
    }
}
