<?php

namespace Tests\Feature\Spreadsheets;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Role;
use App\Models\Spreadsheet;
use App\Models\User;
use App\Services\Spreadsheets\FormulaEngine;
use App\Services\Spreadsheets\SpreadsheetEngine;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * §8.2 of the master spec — a cell's formula re-evaluates against live ERP
 * data every time the sheet is read, the same "living" principle as the
 * document editor's field chips (see LiveTokenChipTest).
 */
class SpreadsheetEngineTest extends TestCase
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
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function invoice(array $overrides = []): Document
    {
        $amount = $overrides['total'] ?? 100000;

        return Document::create(array_merge([
            'type' => DocumentType::Invoice,
            'contact_id' => Contact::create(['name' => 'Un Client'])->id,
            'status' => 'issued',
            'number' => 'INV-'.Str::upper(Str::random(5)),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $amount, 'discount_total' => 0, 'tax_total' => 0,
            'total' => $amount, 'amount_paid' => 0, 'balance' => $amount,
            'created_by' => $this->owner->id,
        ], $overrides));
    }

    /* ------------------------------------------------------------------ *
     * Plain arithmetic and cell references
     * ------------------------------------------------------------------ */

    public function test_a_literal_number_evaluates_to_itself(): void
    {
        $formulas = app(FormulaEngine::class);

        $this->assertSame(42, $formulas->evaluate('42', $this->company, fn () => null));
    }

    public function test_a_plain_string_with_no_leading_equals_is_left_alone(): void
    {
        $formulas = app(FormulaEngine::class);

        $this->assertSame('Revenue', $formulas->evaluate('Revenue', $this->company, fn () => null));
    }

    public function test_basic_arithmetic_with_operator_precedence(): void
    {
        $formulas = app(FormulaEngine::class);

        $this->assertSame(14, $formulas->evaluate('=2+3*4', $this->company, fn () => null));
        $this->assertSame(20, $formulas->evaluate('=(2+3)*4', $this->company, fn () => null));
    }

    public function test_a_sheet_resolves_cell_references_between_cells(): void
    {
        $sheet = Spreadsheet::create([
            'title' => 'Test',
            'cells' => ['A1' => '10', 'B1' => '5', 'C1' => '=A1+B1'],
            'created_by' => $this->owner->id,
        ]);

        $results = app(SpreadsheetEngine::class)->evaluate($sheet, $this->company);

        $this->assertSame(15, $results['C1']);
    }

    public function test_a_circular_reference_reports_an_error_instead_of_looping_forever(): void
    {
        $sheet = Spreadsheet::create([
            'title' => 'Circular',
            'cells' => ['A1' => '=B1', 'B1' => '=A1'],
            'created_by' => $this->owner->id,
        ]);

        $results = app(SpreadsheetEngine::class)->evaluate($sheet, $this->company);

        $this->assertStringContainsString('#ERROR', (string) $results['A1']);
    }

    /* ------------------------------------------------------------------ *
     * OPES_SUM / OPES_LOOKUP
     * ------------------------------------------------------------------ */

    public function test_opes_sum_totals_an_allowlisted_field_across_a_source(): void
    {
        $this->invoice(['total' => 100000, 'balance' => 100000]);
        $this->invoice(['total' => 50000, 'balance' => 50000]);

        $formulas = app(FormulaEngine::class);
        $result = $formulas->evaluate('=OPES_SUM("invoices","total")', $this->company, fn () => null);

        $this->assertSame(150000.0, (float) $result);
    }

    public function test_opes_sum_filters_by_a_field_value_first(): void
    {
        $this->invoice(['total' => 100000, 'status' => 'paid']);
        $this->invoice(['total' => 50000, 'status' => 'issued']);

        $formulas = app(FormulaEngine::class);
        $result = $formulas->evaluate(
            '=OPES_SUM("invoices","status","paid","total")',
            $this->company,
            fn () => null,
        );

        $this->assertSame(100000.0, (float) $result);
    }

    public function test_opes_sum_refuses_a_field_not_on_the_allowlist(): void
    {
        $formulas = app(FormulaEngine::class);

        $this->expectException(RuntimeException::class);

        $formulas->evaluate('=OPES_SUM("invoices","created_by")', $this->company, fn () => null);
    }

    public function test_opes_sum_refuses_an_unknown_source(): void
    {
        $formulas = app(FormulaEngine::class);

        $this->expectException(RuntimeException::class);

        $formulas->evaluate('=OPES_SUM("payroll","salary")', $this->company, fn () => null);
    }

    public function test_opes_lookup_returns_a_single_fields_value_by_key(): void
    {
        $invoice = $this->invoice(['total' => 75000]);

        $formulas = app(FormulaEngine::class);
        $result = $formulas->evaluate(
            '=OPES_LOOKUP("invoices","'.$invoice->number.'","total")',
            $this->company,
            fn () => null,
        );

        $this->assertSame(75000.0, (float) $result);
    }

    public function test_opes_lookup_with_no_match_returns_null_rather_than_erroring(): void
    {
        $formulas = app(FormulaEngine::class);

        $result = $formulas->evaluate('=OPES_LOOKUP("invoices","NO-SUCH-NUMBER","total")', $this->company, fn () => null);

        $this->assertNull($result);
    }

    public function test_a_formula_can_combine_opes_sum_with_arithmetic(): void
    {
        $this->invoice(['total' => 100000]);

        $sheet = Spreadsheet::create([
            'title' => 'Combined',
            'cells' => ['A1' => '=OPES_SUM("invoices","total")', 'B1' => '=A1*1.1925'],
            'created_by' => $this->owner->id,
        ]);

        $results = app(SpreadsheetEngine::class)->evaluate($sheet, $this->company);

        $this->assertEqualsWithDelta(119250, (float) $results['B1'], 0.01);
    }

    public function test_the_current_companys_data_is_never_leaked_to_another_company(): void
    {
        $this->invoice(['total' => 999999]);

        $otherOwner = User::factory()->create();
        $otherCompany = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Ltd',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($otherCompany, $otherOwner, Role::OWNER);
        app(CurrentCompany::class)->set($otherCompany);

        $formulas = app(FormulaEngine::class);
        $result = $formulas->evaluate('=OPES_SUM("invoices","total")', $otherCompany, fn () => null);

        $this->assertSame(0.0, (float) $result);
    }
}
