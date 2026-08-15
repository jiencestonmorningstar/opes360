<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Role;
use App\Models\User;
use App\Support\Aging;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgingTest extends TestCase
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
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    protected function invoice(string $dueDate, float $balance, string $status = 'issued'): Document
    {
        return Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => $status,
            'number' => 'INV-'.Str::upper(Str::random(5)),
            'issue_date' => Carbon::parse($dueDate)->subDays(30)->toDateString(),
            'due_date' => $dueDate,
            'currency' => 'XAF',
            'subtotal' => $balance,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => $balance,
            'amount_paid' => 0,
            'balance' => $balance,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function aging(string $today = '2026-06-30'): Aging
    {
        return new Aging(Carbon::parse($today));
    }

    public function test_an_invoice_not_yet_due_is_current(): void
    {
        $this->invoice('2026-07-15', 100000);

        $report = $this->aging()->receivable();

        $this->assertSame(100000.0, $report['totals']['current']);
        $this->assertSame(0.0, $report['totals']['1_30']);
    }

    public function test_invoices_land_in_the_right_buckets(): void
    {
        $this->invoice('2026-06-20', 10000);  // 10 days over
        $this->invoice('2026-05-20', 20000);  // 41 days over
        $this->invoice('2026-04-20', 30000);  // 71 days over
        $this->invoice('2026-01-20', 40000);  // 161 days over

        $totals = $this->aging()->receivable()['totals'];

        $this->assertSame(10000.0, $totals['1_30']);
        $this->assertSame(20000.0, $totals['31_60']);
        $this->assertSame(30000.0, $totals['61_90']);
        $this->assertSame(40000.0, $totals['over_90']);
    }

    /** Boundaries are where an aging report is usually wrong. */
    public function test_the_bucket_boundaries_are_exact(): void
    {
        $aging = $this->aging();

        $this->assertSame('current', $aging->bucketFor(0), 'due today is not overdue');
        $this->assertSame('1_30', $aging->bucketFor(1));
        $this->assertSame('1_30', $aging->bucketFor(30));
        $this->assertSame('31_60', $aging->bucketFor(31));
        $this->assertSame('31_60', $aging->bucketFor(60));
        $this->assertSame('61_90', $aging->bucketFor(61));
        $this->assertSame('61_90', $aging->bucketFor(90));
        $this->assertSame('over_90', $aging->bucketFor(91));
    }

    public function test_a_document_due_today_is_not_overdue(): void
    {
        $this->invoice('2026-06-30', 50000);

        $this->assertSame(50000.0, $this->aging()->receivable()['totals']['current']);
    }

    /**
     * An invoice on ninety-day terms is not overdue in its second month, and a
     * report that says so trains people to ignore it.
     */
    public function test_age_is_measured_from_the_due_date_not_the_issue_date(): void
    {
        Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => 'issued',
            'number' => 'INV-TERMS',
            'issue_date' => '2026-01-15',
            'due_date' => '2026-07-15',
            'currency' => 'XAF',
            'subtotal' => 90000, 'discount_total' => 0, 'tax_total' => 0,
            'total' => 90000, 'amount_paid' => 0, 'balance' => 90000,
            'created_by' => $this->owner->id,
        ]);

        $this->assertSame(90000.0, $this->aging()->receivable()['totals']['current']);
    }

    public function test_paid_draft_and_void_invoices_are_excluded(): void
    {
        $this->invoice('2026-01-01', 10000, DocumentStatus::Paid->value);
        $this->invoice('2026-01-01', 10000, DocumentStatus::Draft->value);
        $this->invoice('2026-01-01', 10000, DocumentStatus::Void->value);

        $this->assertSame(0.0, $this->aging()->receivable()['total']);
    }

    public function test_quotations_are_not_receivable(): void
    {
        Document::create([
            'type' => DocumentType::Quotation,
            'contact_id' => $this->customer->id,
            'status' => 'sent',
            'number' => 'QUO-1',
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'currency' => 'XAF',
            'subtotal' => 500000, 'discount_total' => 0, 'tax_total' => 0,
            'total' => 500000, 'amount_paid' => 0, 'balance' => 500000,
            'created_by' => $this->owner->id,
        ]);

        $this->assertSame(0.0, $this->aging()->receivable()['total'], 'a quotation is not money owed');
    }

    public function test_rows_are_grouped_by_party_and_sorted_by_size(): void
    {
        $big = Contact::create(['name' => 'Big Debtor', 'balance' => 0]);

        foreach ([300000, 200000] as $amount) {
            Document::create([
                'type' => DocumentType::Invoice, 'contact_id' => $big->id, 'status' => 'issued',
                'number' => 'INV-'.Str::upper(Str::random(5)),
                'issue_date' => '2026-05-01', 'due_date' => '2026-05-30', 'currency' => 'XAF',
                'subtotal' => $amount, 'discount_total' => 0, 'tax_total' => 0,
                'total' => $amount, 'amount_paid' => 0, 'balance' => $amount,
                'created_by' => $this->owner->id,
            ]);
        }

        $this->invoice('2026-05-30', 50000);

        $rows = $this->aging()->receivable()['rows'];

        $this->assertCount(2, $rows);
        $this->assertSame('Big Debtor', $rows[0]['party']);
        $this->assertSame(500000.0, $rows[0]['total']);
        $this->assertCount(2, $rows[0]['items']);
    }

    /** The report exists to point at the worst debt. */
    public function test_a_partys_items_are_oldest_first(): void
    {
        $this->invoice('2026-06-25', 1000);
        $this->invoice('2026-01-25', 2000);

        $items = $this->aging()->receivable()['rows'][0]['items'];

        $this->assertGreaterThan($items[1]['days_overdue'], $items[0]['days_overdue']);
        $this->assertSame(2000.0, $items[0]['amount']);
    }

    public function test_a_missing_due_date_falls_back_to_the_issue_date(): void
    {
        Document::create([
            'type' => DocumentType::Invoice, 'contact_id' => $this->customer->id, 'status' => 'issued',
            'number' => 'INV-NODUE', 'issue_date' => '2026-01-10', 'due_date' => null, 'currency' => 'XAF',
            'subtotal' => 70000, 'discount_total' => 0, 'tax_total' => 0,
            'total' => 70000, 'amount_paid' => 0, 'balance' => 70000,
            'created_by' => $this->owner->id,
        ]);

        $this->assertSame(70000.0, $this->aging()->receivable()['totals']['over_90']);
    }

    // ── Payable ──────────────────────────────────────────────────────────

    protected function bill(string $dueDate, float $total, float $paid = 0, string $status = 'recorded'): Expense
    {
        return Expense::create([
            'supplier_id' => $this->customer->id,
            'number' => 'EXP-'.Str::upper(Str::random(5)),
            'description' => 'Supplies',
            'category' => 'general',
            'issue_date' => Carbon::parse($dueDate)->subDays(30)->toDateString(),
            'due_date' => $dueDate,
            'amount' => $total, 'vat_rate' => 0, 'vat_amount' => 0,
            'total' => $total, 'amount_paid' => $paid,
            'currency' => 'XAF', 'status' => $status,
            'recorded_by' => $this->owner->id,
        ]);
    }

    public function test_unpaid_bills_age_the_same_way(): void
    {
        $this->bill('2026-06-20', 15000);
        $this->bill('2026-03-20', 25000);

        $totals = $this->aging()->payable()['totals'];

        $this->assertSame(15000.0, $totals['1_30']);
        $this->assertSame(25000.0, $totals['over_90']);
    }

    public function test_a_part_paid_bill_ages_only_the_remainder(): void
    {
        $this->bill('2026-06-20', 100000, 60000);

        $this->assertSame(40000.0, $this->aging()->payable()['totals']['1_30']);
    }

    public function test_settled_and_void_bills_are_excluded(): void
    {
        $this->bill('2026-01-01', 50000, 50000);
        $this->bill('2026-01-01', 50000, 0, 'void');

        $this->assertSame(0.0, $this->aging()->payable()['total']);
    }

    /** XAF has no minor unit, so a sub-franc remainder is settled. */
    public function test_a_sub_franc_remainder_is_treated_as_settled(): void
    {
        $this->bill('2026-01-01', 100000, 99999.6);

        $this->assertSame(0.0, $this->aging()->payable()['total']);
    }

    public function test_another_companys_debt_is_invisible(): void
    {
        $this->invoice('2026-01-01', 999000);

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)), 'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id, 'currency' => 'XAF',
            'plan' => 'business', 'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertSame(0.0, $this->aging()->receivable()['total']);
    }

    public function test_the_party_view_returns_an_empty_shape_for_a_clean_customer(): void
    {
        $clean = Contact::create(['name' => 'Pays On Time', 'balance' => 0]);

        $row = $this->aging()->forParty($clean);

        $this->assertSame(0.0, $row['total']);
        $this->assertSame([], $row['items']);
    }
}
