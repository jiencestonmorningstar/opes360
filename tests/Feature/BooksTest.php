<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Accounting\Index as AccountingIndex;
use App\Livewire\Documents\Create;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\PaymentRecorder;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BooksTest extends TestCase
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
            'slug' => 'acme',
            'name' => 'Acme Sarl',
            'owner_id' => $this->user->id,
            'currency' => 'XAF',
            'vat_registered' => true,
            'vat_rate' => 19.25,
        ]);

        $this->joinCompany($this->company, $this->user, Role::OWNER);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);
        $this->contact = Contact::create(['name' => 'Ndongo Ltd']);
    }

    protected function books(): Books
    {
        return app(Books::class);
    }

    protected function sellFor(float $net): Document
    {
        $this->returned(Livewire::actingAs($this->user)
            ->test(Create::class, ['type' => 'invoice'])
            ->call('save', [
                'contact_id' => $this->contact->id,
                'issue_date' => now()->toDateString(),
                'due_date' => null,
                'notes' => '',
                'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => (string) $net]],
            ], true));

        return Document::where('type', DocumentType::Invoice)->latest()->firstOrFail();
    }

    public function test_the_trial_balance_foots(): void
    {
        $document = $this->sellFor(100000);
        app(PaymentRecorder::class)->record($document, $this->user, 50000, PaymentMethod::Cash);

        $balance = $this->books()->trialBalance($this->company);

        $this->assertTrue($balance['balanced'], 'The trial balance did not foot.');
        $this->assertSame($balance['total_debit'], $balance['total_credit']);
    }

    public function test_accounts_with_no_movement_stay_off_the_balance(): void
    {
        $this->sellFor(100000);

        $numbers = $this->books()->trialBalance($this->company)['rows']
            ->map(fn ($r) => $r['account']->number)->all();

        $this->assertContains('411', $numbers);
        // Never touched by a sale, and noise on a balance if listed.
        $this->assertNotContains('401', $numbers);
        $this->assertNotContains('601', $numbers);
    }

    public function test_a_balance_reads_positive_in_the_accounts_own_direction(): void
    {
        $this->sellFor(100000);

        $rows = $this->books()->trialBalance($this->company)['rows']->keyBy(fn ($r) => $r['account']->number);

        // Owed to the business, and owed by it, both read positive.
        $this->assertSame(119250.0, $rows['411']['balance']);
        $this->assertSame(100000.0, $rows['701']['balance']);
        $this->assertSame(19250.0, $rows['443']['balance']);
    }

    public function test_the_grand_livre_carries_a_running_balance(): void
    {
        $document = $this->sellFor(100000);
        app(PaymentRecorder::class)->record($document, $this->user, 119250, PaymentMethod::Cash);

        $account = LedgerAccount::where('number', '411')->firstOrFail();
        $ledger = $this->books()->accountLedger($this->company, $account);

        $this->assertCount(2, $ledger['lines']);
        // Debited by the sale, credited by the settlement, flat at the end.
        $this->assertSame(119250.0, $ledger['lines'][0]['balance']);
        $this->assertSame(0.0, $ledger['lines'][1]['balance']);
        $this->assertSame(0.0, $ledger['closing']);
    }

    public function test_an_opening_balance_carries_in_from_before_the_window(): void
    {
        $this->sellFor(100000);

        $account = LedgerAccount::where('number', '411')->firstOrFail();

        // A window that starts after the sale sees it only as an opening figure.
        $ledger = $this->books()->accountLedger(
            $this->company,
            $account,
            now()->addDay()->toDateString(),
            now()->addWeek()->toDateString(),
        );

        $this->assertSame(119250.0, $ledger['opening']);
        $this->assertCount(0, $ledger['lines']);
    }

    public function test_the_income_statement_reports_the_net_not_the_gross(): void
    {
        $this->sellFor(100000);

        $income = $this->books()->incomeStatement($this->company);

        // Income is the net. The TVA was never the business's money and must
        // not inflate what it says it earned.
        $this->assertSame(100000.0, $income['total_produits']);
        $this->assertSame(0.0, $income['total_charges']);
        $this->assertSame(100000.0, $income['resultat']);
    }

    public function test_the_balance_sheet_balances(): void
    {
        $document = $this->sellFor(100000);
        app(PaymentRecorder::class)->record($document, $this->user, 119250, PaymentMethod::Cash);

        $sheet = $this->books()->balanceSheet($this->company);

        $this->assertTrue($sheet['balanced'], 'Actif and passif did not agree.');
        // Cash in hand on one side; the tax owed plus the period's result on
        // the other.
        $this->assertSame(119250.0, $sheet['total_actif']);
        $this->assertSame(119250.0, $sheet['total_passif']);
    }

    public function test_the_period_window_excludes_what_falls_outside_it(): void
    {
        $this->sellFor(100000);

        $lastMonth = $this->books()->trialBalance(
            $this->company,
            now()->subMonths(2)->toDateString(),
            now()->subMonth()->toDateString(),
        );

        $this->assertCount(0, $lastMonth['rows']);
        $this->assertSame(0.0, $lastMonth['total_debit']);
    }

    public function test_the_page_renders_every_statement(): void
    {
        $this->sellFor(100000);

        foreach (array_keys(AccountingIndex::TABS) as $tab) {
            Livewire::actingAs($this->user)
                ->test(AccountingIndex::class)
                ->set('tab', $tab)
                ->assertOk();
        }
    }

    public function test_a_role_without_the_accounting_right_cannot_open_the_books(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($cashier)->get(route('accounting'))->assertForbidden();
    }

    public function test_an_accountant_can_open_and_export_the_books(): void
    {
        $accountant = User::factory()->create();
        $this->joinCompany($this->company, $accountant, Role::ACCOUNTANT);
        $accountant->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($accountant)->get(route('accounting'))->assertOk();

        Livewire::actingAs($accountant)
            ->test(AccountingIndex::class)
            ->call('exportBalance')
            ->assertOk();
    }

    public function test_a_business_with_no_chart_is_offered_one(): void
    {
        LedgerAccount::query()->delete();

        Livewire::actingAs($this->user)
            ->test(AccountingIndex::class)
            ->assertSee('No chart of accounts yet')
            ->call('seedChart');

        $this->assertSame(count(ChartOfAccounts::starterAccounts()), LedgerAccount::count());
    }
}
