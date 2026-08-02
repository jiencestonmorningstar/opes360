<?php

namespace Tests\Feature;

use App\Livewire\Business\Edit;
use App\Livewire\Payroll\Index as PayrollIndex;
use App\Livewire\Payroll\Show as PayrollShow;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\Accounting\Ledger;
use App\Services\Payroll\PayrollRunner;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A month's payroll, end to end.
 *
 * The arithmetic itself is covered in PayrollCalculatorTest, which needs no
 * database. What is tested here is everything around it: which contract a run
 * reads, what it writes down, where it lands in the books, and who is allowed
 * to press which button.
 */
class PayrollTest extends TestCase
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
            // On the top plan because payroll is a Business-plan module.
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);
    }

    protected function runner(): PayrollRunner
    {
        return app(PayrollRunner::class);
    }

    /** Someone on a flat salary from a year ago, so every month is covered. */
    protected function hire(string $first, string $last, float $salary, ?string $from = null): Employee
    {
        $from ??= now()->subYear()->toDateString();

        $employee = Employee::create([
            'company_id' => $this->company->id,
            'first_name' => $first,
            'last_name' => $last,
            'hired_on' => $from,
            'status' => 'active',
            'payment_method' => 'cash',
        ]);

        EmploymentContract::create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'type' => 'cdi',
            'starts_on' => $from,
            'base_salary' => $salary,
            'status' => 'active',
        ]);

        return $employee;
    }

    protected function lines(PayrollRun $run): array
    {
        $entry = app(Ledger::class)->entryFor($this->company, $run);

        return $entry === null ? [] : $entry->load('lines.account')->lines
            ->mapWithKeys(fn ($l) => [$l->account->number => [round((float) $l->debit, 2), round((float) $l->credit, 2)]])
            ->all();
    }

    // ───────────────────────────────────────────────────────── building ──

    public function test_a_run_produces_a_payslip_for_everyone_with_a_contract(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $this->hire('Paul', 'Atangana', 150000);

        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $this->assertSame(2, $run->headcount);
        $this->assertSame(2, Payslip::query()->count());
        $this->assertSame('450000.00', $run->gross);
    }

    public function test_somebody_with_no_contract_is_skipped_rather_than_paid_nothing(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Sans', 'last_name' => 'Contrat',
            'hired_on' => now()->toDateString(), 'status' => 'active',
        ]);

        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $this->assertSame(1, $run->headcount, 'A payslip for nothing is worse than none.');
    }

    public function test_somebody_who_has_left_is_not_on_later_runs(): void
    {
        $employee = $this->hire('Yvonne', 'Ngo Bell', 300000);
        $employee->update(['status' => 'ended', 'ended_on' => now()->subMonth()->toDateString()]);

        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $this->assertSame(0, $run->headcount);
    }

    /**
     * The reason contracts are a separate table. A raise in the current month
     * must not rewrite what an earlier month was paid at.
     */
    public function test_a_run_reads_the_contract_in_force_for_its_own_month(): void
    {
        $employee = $this->hire('Yvonne', 'Ngo Bell', 200000, now()->subMonths(6)->toDateString());

        // Promoted from the first of this month.
        EmploymentContract::query()->where('employee_id', $employee->id)->update([
            'status' => 'ended',
            'ended_on' => now()->startOfMonth()->subDay()->toDateString(),
        ]);

        EmploymentContract::create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'type' => 'cdi',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'base_salary' => 350000,
            'status' => 'active',
        ]);

        $last = $this->runner()->build($this->runner()->open(now()->subMonth()->toDateString(), $this->owner), $this->owner);
        $this->assertSame('200000.00', $last->gross, 'Last month is still at last month’s salary.');

        $this->travelTo(now()->endOfMonth());
        $now = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);
        $this->assertSame('350000.00', $now->gross);
    }

    public function test_allowances_and_deductions_reach_the_payslip_under_their_own_names(): void
    {
        $employee = $this->hire('Yvonne', 'Ngo Bell', 200000);

        SalaryComponent::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'name' => 'Prime de transport', 'kind' => 'allowance', 'amount' => 25000,
            'taxable' => false, 'cnps_liable' => false, 'active' => true,
        ]);

        SalaryComponent::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'name' => 'Remboursement prêt', 'kind' => 'deduction', 'amount' => 15000, 'active' => true,
        ]);

        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);
        $slip = Payslip::query()->firstOrFail();

        $this->assertSame('225000.00', $slip->gross);
        $this->assertSame('200000.00', $slip->taxable_gross, 'The transport allowance stays out of the tax base.');
        $this->assertSame('15000.00', $slip->other_deductions);
        $this->assertSame('225000.00', $run->gross);

        $labels = $slip->lines()->pluck('label')->all();
        $this->assertContains('Prime de transport', $labels);
        $this->assertContains('Remboursement prêt', $labels);
    }

    public function test_a_paused_component_stops_appearing(): void
    {
        $employee = $this->hire('Yvonne', 'Ngo Bell', 200000);

        SalaryComponent::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'name' => 'Prime', 'kind' => 'allowance', 'amount' => 25000, 'active' => false,
        ]);

        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $this->assertSame('200000.00', $run->gross);
    }

    /** A rebuild replaces the draft rather than adding to it. */
    public function test_rebuilding_a_draft_does_not_double_the_payslips(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $run = $this->runner()->open(now()->toDateString(), $this->owner);
        $this->runner()->build($run, $this->owner);
        $run = $this->runner()->build($run->refresh(), $this->owner);

        $this->assertSame(1, Payslip::query()->count());
        $this->assertSame(1, $run->headcount);
    }

    public function test_only_one_run_can_exist_for_a_month(): void
    {
        $first = $this->runner()->open(now()->toDateString(), $this->owner);
        $second = $this->runner()->open(now()->startOfMonth()->addDays(9)->toDateString(), $this->owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PayrollRun::query()->count());
    }

    /**
     * A payslip carries the person as they were. Correcting a spelling three
     * years later must not reissue history.
     */
    public function test_a_payslip_keeps_the_name_it_was_issued_under(): void
    {
        $employee = $this->hire('Yvone', 'Ngo Bel', 200000);

        $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $employee->update(['first_name' => 'Yvonne', 'last_name' => 'Ngo Bell']);

        $this->assertSame('Yvone Ngo Bel', Payslip::query()->firstOrFail()->employeeName());
    }

    // ─────────────────────────────────────────────────────────── posting ──

    /**
     * The whole month in one entry. Worked from a single 300 000 F salary:
     *
     *   Dr 661 salaires            300 000
     *   Dr 664 charges sociales     38 850   (12 600 + 21 000 + 5 250)
     *   Dr 641 CFC + FNE             7 500   (4 500 + 3 000)
     *     Cr 422 net                262 020
     *     Cr 431 CNPS                51 450   (12 600 employee + 38 850 employer)
     *     Cr 447 impôts retenus      32 880   (15 573 + 1 557 + 3 000 + 2 000 + 3 250 + 7 500)
     */
    public function test_approving_posts_the_month_to_the_books(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);
        $run = $this->runner()->approve($run, $this->owner);

        $lines = $this->lines($run);

        $this->assertSame([300000.0, 0.0], $lines['661']);
        $this->assertSame([38850.0, 0.0], $lines['664']);
        $this->assertSame([7500.0, 0.0], $lines['641']);
        $this->assertSame([0.0, 262020.0], $lines['422']);
        $this->assertSame([0.0, 51450.0], $lines['431']);
        $this->assertSame([0.0, 32880.0], $lines['447']);
    }

    /** The invariant that makes a ledger a ledger, on the largest entry here. */
    public function test_the_payroll_entry_balances(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $employee = $this->hire('Paul', 'Atangana', 950000);

        SalaryComponent::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'name' => 'Transport', 'kind' => 'allowance', 'amount' => 25000,
            'taxable' => false, 'cnps_liable' => false, 'active' => true,
        ]);

        SalaryComponent::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'name' => 'Avance', 'kind' => 'deduction', 'amount' => 40000, 'active' => true,
        ]);

        $run = $this->runner()->approve(
            $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner),
            $this->owner
        );

        $lines = $this->lines($run);

        $debits = round(array_sum(array_column($lines, 0)), 2);
        $credits = round(array_sum(array_column($lines, 1)), 2);

        $this->assertSame($debits, $credits);
        $this->assertArrayHasKey('421', $lines, 'What was recovered from a payslip clears an advance.');
        $this->assertSame([0.0, 40000.0], $lines['421']);
    }

    public function test_paying_the_run_clears_what_is_owed_to_staff(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $run = $this->runner()->approve(
            $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner),
            $this->owner
        );

        $run = $this->runner()->markPaid($run, ['method' => 'bank', 'paid_on' => now()->toDateString()], $this->owner);

        $this->assertTrue($run->isPaid());

        $entry = JournalEntry::query()
            ->where('journal', 'BQ')
            ->with('lines.account')
            ->latest('created_at')
            ->firstOrFail();

        $lines = $entry->lines->mapWithKeys(fn ($l) => [$l->account->number => [(float) $l->debit, (float) $l->credit]])->all();

        $this->assertSame([262020.0, 0.0], $lines['422']);
        $this->assertSame([0.0, 262020.0], $lines['521']);
        // What is owed to the CNPS and the DGI is untouched: separate dates,
        // separate payments.
        $this->assertArrayNotHasKey('431', $lines);
        $this->assertArrayNotHasKey('447', $lines);
    }

    public function test_a_run_reaches_the_income_statement_as_a_cost(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $this->runner()->approve(
            $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner),
            $this->owner
        );

        $statement = app(Books::class)->incomeStatement(
            $this->company,
            now()->startOfYear()->toDateString(),
            now()->endOfYear()->toDateString(),
        );

        // 300 000 wages + 38 850 social charges + 7 500 taxes.
        $this->assertSame(346350.0, round($statement['total_charges'], 2));
        $this->assertTrue($statement['charges']->contains(fn ($r) => $r['account']->number === '661'));
    }

    // ──────────────────────────────────────────────────────────── states ──

    public function test_an_approved_run_cannot_be_rebuilt(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $run = $this->runner()->approve(
            $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner),
            $this->owner
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a draft');

        $this->runner()->build($run, $this->owner);
    }

    public function test_a_run_cannot_be_approved_twice(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $run = $this->runner()->approve(
            $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner),
            $this->owner
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been approved');

        $this->runner()->approve($run, $this->owner);
    }

    public function test_an_empty_run_cannot_be_approved(): void
    {
        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing to approve');

        $this->runner()->approve($run, $this->owner);
    }

    public function test_a_paid_run_cannot_be_voided(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $run = $this->runner()->markPaid(
            $this->runner()->approve(
                $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner),
                $this->owner
            ),
            ['method' => 'cash', 'paid_on' => now()->toDateString()],
            $this->owner
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been paid');

        $this->runner()->void($run, $this->owner);
    }

    /** Voided, and its entry reversed rather than deleted. */
    public function test_voiding_reverses_the_entry_instead_of_erasing_it(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $run = $this->runner()->approve(
            $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner),
            $this->owner
        );

        $this->runner()->void($run, $this->owner);

        $this->assertSame(2, JournalEntry::query()->where('journal', 'OD')->count());
        $this->assertSame(0.0, round(app(Books::class)->incomeStatement(
            $this->company,
            now()->startOfYear()->toDateString(),
            now()->endOfYear()->toDateString(),
        )['total_charges'], 2), 'The two entries cancel out.');
    }

    /**
     * The rates are copied onto the run so a payslip stays explainable after
     * the next finance act.
     */
    public function test_an_approved_run_records_the_rates_it_used(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $run = $this->runner()->approve(
            $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner),
            $this->owner
        );

        $this->assertSame(0.042, $run->rates['cnps']['pension']['employee']);
        $this->assertSame(750000, $run->rates['cnps']['ceiling']);
    }

    // ────────────────────────────────────────────────────────── the screen ──

    public function test_the_screen_builds_and_approves_a_month(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        Livewire::actingAs($this->owner)
            ->test(PayrollIndex::class)
            ->set('period', now()->startOfMonth()->toDateString())
            ->call('start')
            ->assertRedirect();

        $run = PayrollRun::query()->firstOrFail();
        $this->assertSame(1, $run->headcount);

        Livewire::actingAs($this->owner)
            ->test(PayrollShow::class, ['run' => $run])
            ->call('approve')
            ->assertOk();

        $this->assertTrue($run->refresh()->isApproved());
    }

    public function test_the_run_page_shows_what_is_owed_to_cnps_and_the_dgi(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        Livewire::actingAs($this->owner)
            ->test(PayrollShow::class, ['run' => $run])
            ->assertViewHas('cnpsDue', 51450.0)
            ->assertViewHas('taxDue', 32880.0);
    }

    public function test_a_payslip_prints(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $this->actingAs($this->owner)
            ->get(route('payslips.print', Payslip::query()->firstOrFail()))
            ->assertOk()
            ->assertSee('Bulletin de paie')
            ->assertSee('Yvonne Ngo Bell');
    }

    /**
     * The CNPS and DGI returns are filled in from the register, and the
     * employer's charges are on it — they never appear on an employee's copy
     * but are exactly what the CNPS return is built from.
     */
    public function test_the_register_exports_every_figure_including_the_employer_side(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $response = $this->actingAs($this->owner)->get(route('payroll.register', $run));
        $response->assertOk();

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertSame('Nom', $rows[0][1]);
        $this->assertContains('CNPS accidents du travail', $rows[0]);
        $this->assertSame('Yvonne Ngo Bell', $rows[1][1]);
        $this->assertContains('262020.00', $rows[1], 'The net has to be on the register.');
        $this->assertContains('46350.00', $rows[1], 'And so do the employer charges.');
    }

    /**
     * The two classifications CNPS assigns a business change the employer's
     * bill without changing anybody's net, so they have to be settable.
     */
    public function test_the_business_risk_group_changes_what_a_run_costs(): void
    {
        $this->company->forceFill(['cnps_risk_group' => 'c'])->save();
        app(CurrentCompany::class)->set($this->company->fresh());

        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        // 300 000 × 5% rather than × 1.75%.
        $this->assertSame('15000.00', Payslip::query()->firstOrFail()->cnps_employer_risk);
        $this->assertSame('56100.00', $run->employer_charges);
    }

    public function test_the_business_screen_records_the_cnps_registration(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Edit::class)
            ->set('form.cnps_employer_number', '9-01-2019-0004821')
            ->set('form.cnps_risk_group', 'b')
            ->set('form.cnps_family_regime', 'agricultural')
            ->call('save')
            ->assertHasNoErrors();

        $company = $this->company->fresh();

        $this->assertSame('9-01-2019-0004821', $company->cnps_employer_number);
        $this->assertSame('b', $company->cnps_risk_group);
        $this->assertSame('agricultural', $company->cnps_family_regime);
    }

    /** A business can stop withholding a charge it cannot yet stand behind. */
    public function test_a_business_can_switch_off_the_audiovisual_levy(): void
    {
        $this->company->forceFill(['payroll_settings' => ['rav' => false]])->save();
        app(CurrentCompany::class)->set($this->company->fresh());

        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $slip = Payslip::query()->firstOrFail();

        $this->assertSame('0.00', $slip->rav);
        $this->assertSame('2000.00', $slip->tdl, 'The other one is untouched.');
    }

    /**
     * And can reach that switch. A safety valve only settable by editing the
     * database is not a safety valve.
     */
    public function test_the_business_screen_switches_a_withholding_off_and_on(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Edit::class)
            ->set('form.withhold_rav', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->company->fresh()->payroll_settings['rav']);
        $this->assertTrue($this->company->fresh()->payroll_settings['tdl'], 'Untouched.');

        app(CurrentCompany::class)->set($this->company->fresh());
        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $this->assertSame('0.00', Payslip::query()->firstOrFail()->rav);

        Livewire::actingAs($this->owner)
            ->test(Edit::class)
            ->set('form.withhold_rav', true)
            ->call('save');

        $this->assertTrue($this->company->fresh()->payroll_settings['rav']);
    }

    /**
     * The run says so when it has withheld a levy on an unchecked scale.
     *
     * The band amounts are the one figure in this module that could not be
     * verified against a primary source, so the software says that where the
     * money is — not only in a comment in the config file.
     */
    public function test_a_run_flags_an_unchecked_audiovisual_scale(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        Livewire::actingAs($this->owner)
            ->test(PayrollShow::class, ['run' => $run])
            ->assertViewHas('ravUnverified', true)
            ->assertSee('has not been checked');
    }

    public function test_confirming_the_scale_silences_the_flag(): void
    {
        $this->company->forceFill(['payroll_settings' => ['rav_confirmed' => true]])->save();
        app(CurrentCompany::class)->set($this->company->fresh());

        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        Livewire::actingAs($this->owner)
            ->test(PayrollShow::class, ['run' => $run])
            ->assertViewHas('ravUnverified', false);
    }

    /** A warning on a run that withheld nothing is noise. */
    public function test_a_run_that_withheld_no_levy_says_nothing_about_it(): void
    {
        // Under the 50 000 F floor, so no levy and nothing to warn about.
        $this->hire('Ibrahim', 'Sali', 40000);
        $run = $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $this->assertSame('0.00', Payslip::query()->firstOrFail()->rav);

        Livewire::actingAs($this->owner)
            ->test(PayrollShow::class, ['run' => $run])
            ->assertViewHas('ravUnverified', false);
    }

    /** And the confirmation is reachable from the settings screen. */
    public function test_the_business_screen_records_that_the_scale_was_checked(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Edit::class)
            ->set('form.rav_confirmed', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($this->company->fresh()->payroll_settings['rav_confirmed']);
    }

    /** A business that has never touched the setting withholds what the law asks. */
    public function test_the_withholdings_default_to_on(): void
    {
        $this->assertNull($this->company->payroll_settings);

        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $slip = Payslip::query()->firstOrFail();

        $this->assertSame('3250.00', $slip->rav);
        $this->assertSame('2000.00', $slip->tdl);
    }

    // ─────────────────────────────────────────────────── who may do what ──

    /**
     * Salaries are the one thing everybody is curious about and almost nobody
     * should see. A cashier gets a 403, not an empty list.
     */
    public function test_a_cashier_cannot_see_the_payroll_at_all(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');

        $this->actingAs($cashier)->get(route('payroll'))->assertForbidden();
        $this->actingAs($cashier)->get(route('team'))->assertForbidden();
    }

    /**
     * An accountant runs the payroll; approving it commits the business to a
     * month's wages and the declarations that follow, which is the owner's
     * signature.
     */
    public function test_an_accountant_can_run_a_month_but_not_approve_it(): void
    {
        $accountant = User::factory()->create();
        $this->joinCompany($this->company, $accountant, 'accountant');
        $this->hire('Yvonne', 'Ngo Bell', 300000);

        Livewire::actingAs($accountant)
            ->test(PayrollIndex::class)
            ->set('period', now()->startOfMonth()->toDateString())
            ->call('start')
            ->assertRedirect();

        $run = PayrollRun::query()->firstOrFail();

        Livewire::actingAs($accountant)
            ->test(PayrollShow::class, ['run' => $run])
            ->call('approve')
            ->assertForbidden();
    }

    /**
     * Payroll is what the top plan is for. A Basic-plan Owner is still a
     * Basic-plan Owner — no role escalates past what the business pays for.
     */
    public function test_payroll_is_refused_on_a_plan_that_does_not_include_it(): void
    {
        $this->company->forceFill(['plan' => 'basic'])->save();
        app(CurrentCompany::class)->set($this->company->fresh());

        $this->actingAs($this->owner)->get(route('payroll'))->assertForbidden();
        // The staff file comes one tier lower, so it is refused here too.
        $this->actingAs($this->owner)->get(route('team'))->assertForbidden();

        $this->company->forceFill(['plan' => 'growth'])->save();
        app(CurrentCompany::class)->set($this->company->fresh());

        $this->actingAs($this->owner)->get(route('team'))->assertOk();
        $this->actingAs($this->owner)->get(route('payroll'))->assertForbidden();
    }

    public function test_a_run_belongs_to_its_company_alone(): void
    {
        $this->hire('Yvonne', 'Ngo Bell', 300000);
        $this->runner()->build($this->runner()->open(now()->toDateString(), $this->owner), $this->owner);

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF', 'plan' => 'basic', 'account_type' => 'active',
        ]);

        $this->joinCompany($other, $otherOwner, Role::OWNER);
        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, PayrollRun::query()->count());
        $this->assertSame(0, Payslip::query()->count());
        $this->assertSame(0, Employee::query()->count());
    }
}
