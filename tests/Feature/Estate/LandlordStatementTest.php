<?php

namespace Tests\Feature\Estate;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Expense;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\RecurringInvoice;
use App\Models\Role;
use App\Models\Tenancy;
use App\Models\TenancyRentChange;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\DocumentIssuer;
use App\Services\Estate\Landlords;
use App\Services\Estate\Tenancies;
use App\Services\PaymentRecorder;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use App\Support\LandlordStatement;
use App\Support\Modules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The landlord's account: rent collected minus commission minus what was
 * spent on the building minus what was already paid out. Every figure comes
 * from a class that already owns it (payments, expenses); this statement is
 * arithmetic over them, never a second copy of money.
 */
class LandlordStatementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $landlord;

    protected Property $property;

    protected PropertyUnit $unit;

    protected Contact $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'immo-'.Str::lower(Str::random(4)),
            'name' => 'Immo Douala Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        // Estate ships off; the business under test switches it on. Payables
        // rides along for the payout path.
        $this->company->forceFill(['modules' => ['estate' => true, 'payables' => true]])->save();
        Modules::flush();

        app(CurrentCompany::class)->set($this->company);

        foreach (['estate.view', 'estate.manage', 'estate.end-tenancy'] as $ability) {
            Gate::define($ability, fn (User $user) => true);
        }

        ChartOfAccounts::seed($this->company);

        $this->landlord = Contact::create(['name' => 'M. Etonde Njoh', 'balance' => 0]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Immeuble Bonanjo',
            'kind' => 'residential',
            'landlord_contact_id' => $this->landlord->id,
            'commission_percent' => 10,
            'created_by' => $this->owner->id,
        ]);

        $this->unit = PropertyUnit::create([
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'label' => 'Apartment 2B',
            'target_rent' => 150000,
            'status' => 'vacant',
        ]);

        $this->tenant = Contact::create(['name' => 'Marie Ngo', 'balance' => 0]);
    }

    protected function start(array $overrides = []): Tenancy
    {
        return app(Tenancies::class)->start($this->unit, array_merge([
            'tenant_contact_id' => $this->tenant->id,
            'rent' => 150000,
            'deposit_amount' => 0,
            'moved_in_on' => now()->subMonths(2)->toDateString(),
        ], $overrides), $this->owner);
    }

    /** An issued rent invoice inside the tenancy window, paid in full today. */
    protected function collectRent(float $amount): Document
    {
        $invoice = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->tenant->id,
            'status' => DocumentStatus::Draft,
            'number' => 'INV-'.Str::upper(Str::random(6)),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $amount,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'created_by' => $this->owner->id,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'description' => 'Rent — Apartment 2B',
            'quantity' => 1,
            'unit' => 'month',
            'unit_price' => $amount,
            'tax_amount' => 0,
            'line_total' => $amount,
            'sort_order' => 0,
        ]);

        $invoice = app(DocumentIssuer::class)->issue($invoice->fresh(), $this->owner);

        app(PaymentRecorder::class)->record($invoice, $this->owner, $amount, PaymentMethod::Cash);

        return $invoice->fresh();
    }

    protected function statement(): array
    {
        return (new LandlordStatement(
            $this->landlord,
            now()->startOfMonth(),
            now(),
        ))->build();
    }

    // ─────────────────────────────────────────────────────── the statement ──

    public function test_the_statement_nets_rent_commission_and_expenses(): void
    {
        $this->start();
        $this->collectRent(150000);

        app(Landlords::class)->recordPropertyExpense($this->property, [
            'description' => 'Plumbing repair',
            'amount' => 25000,
            'payment_method' => 'cash',
        ], $this->owner);

        $statement = $this->statement();

        // 150 000 in, 15 000 commission at 10%, 25 000 spent on the building.
        $this->assertSame(150000.0, $statement['totals']['collected']);
        $this->assertSame(15000.0, $statement['totals']['commission']);
        $this->assertSame(25000.0, $statement['totals']['expenses']);
        $this->assertSame(110000.0, $statement['closing_balance']);

        // Money moved through the real recorders — the books must foot.
        $this->assertTrue(app(Books::class)->trialBalance($this->company)['balanced']);
    }

    public function test_rent_billed_but_not_collected_is_not_owed_to_the_landlord(): void
    {
        $this->start();

        // Issued, never paid: the landlord is owed what came in, not what
        // was asked for.
        Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->tenant->id,
            'status' => DocumentStatus::Issued,
            'number' => 'INV-'.Str::upper(Str::random(6)),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 150000,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 150000,
            'amount_paid' => 0,
            'balance' => 150000,
            'created_by' => $this->owner->id,
        ]);

        $statement = $this->statement();

        $this->assertSame(0.0, $statement['totals']['collected']);
        $this->assertSame(0.0, $statement['closing_balance']);
    }

    public function test_another_tenants_money_is_not_the_landlords(): void
    {
        $this->start();

        // A customer who rents nothing here pays an invoice; the statement
        // must not sweep it in.
        $stranger = Contact::create(['name' => 'Paul Etame', 'balance' => 0]);
        $other = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $stranger->id,
            'status' => DocumentStatus::Issued,
            'number' => 'INV-'.Str::upper(Str::random(6)),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 99000,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 99000,
            'amount_paid' => 0,
            'balance' => 99000,
            'created_by' => $this->owner->id,
        ]);
        app(PaymentRecorder::class)->record($other, $this->owner, 99000, PaymentMethod::Cash);

        $this->assertSame(0.0, $this->statement()['totals']['collected']);
    }

    // ─────────────────────────────────────────────────────────── the payout ──

    public function test_the_payout_is_an_expense_to_the_landlord_through_the_recorder(): void
    {
        $this->start();
        $this->collectRent(150000);

        $expense = app(Landlords::class)->payOut($this->property, [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ], $this->owner);

        // An ordinary payable to the landlord contact, pinned to the property.
        $this->assertInstanceOf(Expense::class, $expense);
        $this->assertSame($this->landlord->id, $expense->supplier_id);
        $this->assertSame($this->property->id, $expense->property_id);
        $this->assertSame(135000.0, (float) $expense->total);
        $this->assertStringContainsString('LL-PAYOUT', $expense->reference);

        // The statement shows it as a payout line and the balance closes at 0.
        $statement = $this->statement();
        $this->assertSame(135000.0, $statement['totals']['paid_out']);
        $this->assertSame(0.0, $statement['closing_balance']);

        $this->assertTrue(app(Books::class)->trialBalance($this->company)['balanced']);
    }

    public function test_the_same_period_cannot_be_paid_out_twice(): void
    {
        $this->start();
        $this->collectRent(150000);

        $period = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()];

        app(Landlords::class)->payOut($this->property, $period, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nothing is owed/i');

        app(Landlords::class)->payOut($this->property, $period, $this->owner);
    }

    public function test_paying_out_more_than_is_owed_is_refused(): void
    {
        $this->start();
        $this->collectRent(150000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/more than the statement/i');

        app(Landlords::class)->payOut($this->property, [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'amount' => 200000,
        ], $this->owner);
    }

    public function test_a_self_owned_building_has_nobody_to_pay(): void
    {
        $this->property->forceFill(['landlord_contact_id' => null])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no landlord/i');

        app(Landlords::class)->payOut($this->property->refresh(), [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ], $this->owner);
    }

    // ──────────────────────────────────────────────────────── rent reviews ──

    public function test_a_rent_review_keeps_history_and_rewrites_the_schedule_line(): void
    {
        $tenancy = $this->start(['moved_in_on' => now()->toDateString()]);

        $tenancy = app(Tenancies::class)->reviewRent($tenancy, [
            'rent' => 165000,
            'reason' => 'Annual review',
        ], $this->owner);

        $this->assertSame(165000.0, (float) $tenancy->rent);

        $change = TenancyRentChange::query()->firstOrFail();
        $this->assertSame(150000.0, (float) $change->rent_before);
        $this->assertSame(165000.0, (float) $change->rent_after);
        $this->assertSame('Annual review', $change->reason);

        // The schedule — the existing recurring mechanism — carries the new
        // figure; the next generated invoice bills it without knowing why.
        $schedule = $tenancy->rentSchedule->refresh();
        $this->assertSame(RecurringInvoice::ACTIVE, $schedule->status);
        $this->assertSame(165000.0, (float) $schedule->lines[0]['unit_price']);
    }

    public function test_a_rent_review_cannot_be_backdated(): void
    {
        $tenancy = $this->start();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/backdated/i');

        app(Tenancies::class)->reviewRent($tenancy, [
            'rent' => 165000,
            'effective_on' => now()->subMonth()->toDateString(),
        ], $this->owner);
    }

    public function test_a_rent_review_beyond_the_next_billing_run_is_refused(): void
    {
        $tenancy = $this->start(['moved_in_on' => now()->toDateString()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/next to bill/i');

        app(Tenancies::class)->reviewRent($tenancy, [
            'rent' => 165000,
            'effective_on' => now()->addMonths(3)->toDateString(),
        ], $this->owner);
    }

    public function test_an_ended_tenancy_has_no_rent_to_review(): void
    {
        $tenancy = $this->start();
        app(Tenancies::class)->endTenancy($tenancy, [], $this->owner);

        $this->expectException(RuntimeException::class);

        app(Tenancies::class)->reviewRent($tenancy->refresh(), ['rent' => 165000], $this->owner);
    }
}
