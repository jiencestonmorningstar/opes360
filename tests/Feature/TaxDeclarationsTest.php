<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Accounting\Declarations as DeclarationsScreen;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LedgerAccount;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\TaxDeclarations;
use App\Services\DocumentIssuer;
use App\Services\ExpenseRecorder;
use App\Services\Payroll\PayrollRunner;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The month's returns.
 *
 * Both the TVA and the levies on wages fall due on the fifteenth of the
 * following month whether or not anybody has added them up, and adding them up
 * by hand from a journal is where the errors come from. These figures are a
 * worksheet to copy onto the official forms — never the forms themselves, and
 * the screen says so before it shows a number.
 *
 * The TVA is read off the ledger rather than off the invoice list. Those agree
 * once everything has posted and diverge when it has not, and the books are
 * what a business is audited against.
 */
class TaxDeclarationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $customer;

    /** The month being declared: last month, which is what the screen opens on. */
    protected Carbon $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->period = now()->subMonth()->startOfMonth();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            'vat_registered' => true,
            'vat_rate' => 19.25,
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    // ───────────────────────────────────────────────────────── TVA ──

    public function test_tva_collected_comes_off_the_ledger(): void
    {
        $this->invoice(1000000, 192500);

        $vat = $this->declare();

        $this->assertSame(192500.0, $vat['collected']);
        $this->assertSame(1000000.0, $vat['turnover']);
    }

    public function test_tva_paid_on_purchases_is_deductible(): void
    {
        $this->invoice(1000000, 192500);
        $this->expense(400000, 0.1925);

        $vat = $this->declare();

        $this->assertSame(77000.0, $vat['deductible']);
        $this->assertSame(115500.0, $vat['due'], 'Facturée less récupérable.');
        $this->assertSame(0.0, $vat['credit']);
    }

    /**
     * A month with a big purchase collects less than it can reclaim. That is a
     * crédit carried forward, not a refund and not a negative payment, so it is
     * reported as its own figure.
     */
    public function test_more_reclaimable_than_collected_is_a_credit_not_a_negative_bill(): void
    {
        $this->invoice(100000, 19250);
        $this->expense(1000000, 0.1925);

        $vat = $this->declare();

        $this->assertSame(0.0, $vat['due']);
        $this->assertSame(173250.0, $vat['credit']);
    }

    public function test_another_months_entries_are_not_declared(): void
    {
        $this->invoice(1000000, 192500);
        $this->invoice(500000, 96250, $this->period->copy()->subMonth());

        $vat = $this->declare();

        $this->assertSame(192500.0, $vat['collected'], 'Only the month asked for.');
    }

    public function test_sales_that_carried_no_tva_are_reported_separately(): void
    {
        $this->invoice(1000000, 192500);
        $this->invoice(300000, 0);

        $vat = $this->declare();

        $this->assertSame(1300000.0, $vat['turnover']);
        $this->assertSame(1000000.0, $vat['taxed_turnover']);
        $this->assertSame(300000.0, $vat['exempt_turnover']);
    }

    public function test_every_tva_entry_is_listed_so_a_figure_can_be_traced(): void
    {
        $this->invoice(1000000, 192500);
        $this->expense(400000, 0.1925);

        $vat = $this->declare();

        $this->assertCount(2, $vat['lines']);
        $this->assertSame(['collected', 'deductible'], $vat['lines']->pluck('kind')->sort()->values()->all());
    }

    public function test_a_business_with_no_entries_declares_nothing_rather_than_failing(): void
    {
        $vat = $this->declare();

        $this->assertSame(0.0, $vat['collected']);
        $this->assertSame(0.0, $vat['due']);
        $this->assertSame(0.0, $vat['credit']);
    }

    // ───────────────────────────────────────────────────── wages ──

    public function test_the_cnps_and_dgi_totals_come_from_approved_payslips(): void
    {
        $this->approvedPayroll(300000);

        $payroll = $this->declarePayroll();

        $this->assertSame(1, $payroll['headcount']);
        $this->assertSame(300000.0, $payroll['gross']);

        // Both sides of every CNPS line, which is one payment on one form.
        $this->assertSame(
            round($payroll['cnps_employee'] + $payroll['cnps_employer_pension']
                + $payroll['cnps_employer_family'] + $payroll['cnps_employer_risk'], 2),
            $payroll['cnps_total']
        );

        $this->assertSame(
            round($payroll['irpp'] + $payroll['cac'] + $payroll['cfc_employee']
                + $payroll['cfc_employer'] + $payroll['tdl'] + $payroll['rav'] + $payroll['fne'], 2),
            $payroll['dgi_total']
        );

        $this->assertGreaterThan(0, $payroll['cnps_total']);
        $this->assertGreaterThan(0, $payroll['dgi_total']);
    }

    /**
     * A draft run is a rehearsal — its figures move every time somebody edits
     * a contract. Declaring one would mean declaring a month that has not
     * happened.
     */
    public function test_a_draft_run_is_not_declared(): void
    {
        $this->approvedPayroll(300000, approve: false);

        $payroll = $this->declarePayroll();

        $this->assertSame(0, $payroll['headcount']);
        $this->assertSame(0.0, $payroll['cnps_total']);
    }

    public function test_another_months_payroll_is_not_declared(): void
    {
        $this->approvedPayroll(300000, period: $this->period->copy()->subMonth());

        $this->assertSame(0, $this->declarePayroll()['headcount']);
    }

    // ─────────────────────────────────────────────────── the screen ──

    public function test_the_screen_opens_on_last_month_because_that_is_what_is_due(): void
    {
        Livewire::actingAs($this->owner)
            ->test(DeclarationsScreen::class)
            ->assertSet('month', now()->subMonth()->format('Y-m'));
    }

    public function test_the_screen_says_it_is_a_worksheet_before_it_shows_a_figure(): void
    {
        $this->invoice(1000000, 192500);

        Livewire::actingAs($this->owner)
            ->test(DeclarationsScreen::class)
            ->assertSee('A worksheet, not a filing');
    }

    public function test_the_month_can_be_stepped_back(): void
    {
        Livewire::actingAs($this->owner)
            ->test(DeclarationsScreen::class)
            ->call('previousMonth')
            ->assertSet('month', now()->subMonths(2)->format('Y-m'));
    }

    /** There is nothing to declare about a month that has not started. */
    public function test_the_month_cannot_be_stepped_past_this_one(): void
    {
        Livewire::actingAs($this->owner)
            ->test(DeclarationsScreen::class)
            ->call('nextMonth')
            ->assertSet('month', now()->format('Y-m'))
            ->call('nextMonth')
            ->assertSet('month', now()->format('Y-m'));
    }

    public function test_the_screen_shows_the_due_date(): void
    {
        Livewire::actingAs($this->owner)
            ->test(DeclarationsScreen::class)
            ->assertViewHas('dueOn', fn ($due) => $due->day === 15
                && $due->format('Y-m') === now()->format('Y-m'));
    }

    public function test_the_tva_worksheet_downloads(): void
    {
        $this->invoice(1000000, 192500);

        $csv = $this->download('exportVat');

        $this->assertStringContainsString('TVA facturée (443)', $csv);
        $this->assertStringContainsString('192500', $csv);
        $this->assertStringContainsString('Détail des écritures', $csv);
    }

    public function test_the_wages_worksheet_downloads(): void
    {
        $this->approvedPayroll(300000);

        $csv = $this->download('exportPayroll');

        $this->assertStringContainsString('Total dû à la CNPS', $csv);
        $this->assertStringContainsString('Total dû à la DGI', $csv);
    }

    /**
     * Reading the books and taking a copy of them out of the building are
     * different permissions. No seeded role has one without the other, so the
     * export is revoked explicitly for this manager — which is exactly the
     * per-user grant the permission table exists for.
     */
    public function test_someone_who_may_not_export_cannot_download(): void
    {
        $manager = User::factory()->create();
        $this->joinCompany($this->company, $manager, Role::MANAGER);

        DB::table('company_user_permission')->insert([
            'company_id' => $this->company->id,
            'user_id' => $manager->id,
            'permission_id' => DB::table('permissions')->where('slug', 'accounting.export')->value('id'),
            'granted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($manager)
            ->test(DeclarationsScreen::class)
            ->call('exportVat')
            ->assertForbidden();
    }

    public function test_someone_who_may_not_see_the_books_cannot_open_it(): void
    {
        $clerk = User::factory()->create();
        $this->joinCompany($this->company, $clerk, Role::CASHIER);

        $this->actingAs($clerk)->get(route('accounting.declarations'))->assertForbidden();
    }

    public function test_a_business_with_no_chart_is_told_so_rather_than_shown_zeroes(): void
    {
        LedgerAccount::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->delete();

        Livewire::actingAs($this->owner)
            ->test(DeclarationsScreen::class)
            ->assertSee('No books to declare from yet');
    }

    // ───────────────────────────────────────────────────── helpers ──

    /**
     * The CSV a download action streams.
     *
     * Called on the component directly: Livewire's test harness does not
     * surface a StreamedResponse returned from an action, and asserting on the
     * bytes is the only way to know the worksheet says what it should.
     */
    protected function download(string $method): string
    {
        $this->actingAs($this->owner);

        $screen = new DeclarationsScreen;
        $screen->month = $this->period->format('Y-m');

        $response = $screen->{$method}(app(TaxDeclarations::class));

        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    /** @return array<string, mixed> */
    protected function declare(): array
    {
        return app(TaxDeclarations::class)->vat(
            $this->company,
            $this->period->toDateString(),
            $this->period->copy()->endOfMonth()->toDateString(),
        );
    }

    /** @return array<string, mixed> */
    protected function declarePayroll(): array
    {
        return app(TaxDeclarations::class)->payroll(
            $this->company,
            $this->period->toDateString(),
            $this->period->copy()->endOfMonth()->toDateString(),
        );
    }

    protected function invoice(float $net, float $tax, ?Carbon $on = null): Document
    {
        $on ??= $this->period->copy()->addDays(5);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => $on->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $net, 'tax_total' => $tax,
            'total' => $net + $tax, 'balance' => $net + $tax,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Fourniture',
            'quantity' => 1,
            'unit_price' => $net,
            'tax_amount' => $tax,
            'line_total' => $net,
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->owner);
    }

    protected function expense(float $net, float $vatRate): void
    {
        app(ExpenseRecorder::class)->record([
            'description' => 'Achat de marchandises',
            'category' => 'goods',
            'issue_date' => $this->period->copy()->addDays(9)->toDateString(),
            'amount' => $net,
            'vat_rate' => $vatRate,
            'payment_method' => 'cash',
        ], $this->owner);
    }

    protected function approvedPayroll(float $salary, bool $approve = true, ?Carbon $period = null): PayrollRun
    {
        $period ??= $this->period;

        $employee = Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Marie', 'last_name' => 'Ngo',
            'hired_on' => $period->copy()->subYear()->toDateString(),
            'status' => 'active',
            'payment_method' => 'bank',
        ]);

        EmploymentContract::create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'type' => 'cdi',
            'starts_on' => $period->copy()->subYear()->toDateString(),
            'base_salary' => $salary,
            'status' => 'active',
        ]);

        // Through the runner, which is the only thing that knows a run needs
        // a currency and a pay date as well as a month.
        $run = app(PayrollRunner::class)->open($period->copy()->startOfMonth()->toDateString(), $this->owner);

        app(PayrollRunner::class)->build($run, $this->owner);

        if ($approve) {
            $run = app(PayrollRunner::class)->approve($run->fresh(), $this->owner);
        }

        return $run;
    }
}
