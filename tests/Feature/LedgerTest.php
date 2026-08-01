<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Documents\Create;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\PaymentRecorder;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class LedgerTest extends TestCase
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

    protected function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    public function test_the_starter_chart_sets_the_right_side_for_each_account(): void
    {
        $side = fn (string $number) => LedgerAccount::where('number', $number)->firstOrFail()->normal_balance;

        // Assets and charges are debit-normal.
        $this->assertSame('debit', $side('411')); // Clients — owed to the business
        $this->assertSame('debit', $side('521')); // Banques
        $this->assertSame('debit', $side('571')); // Caisse
        $this->assertSame('debit', $side('601')); // Achats
        // Tax the business has paid and can reclaim is an asset, despite
        // sitting in class 4 beside its opposite.
        $this->assertSame('debit', $side('445'));

        // Liabilities and income are credit-normal.
        $this->assertSame('credit', $side('401')); // Fournisseurs
        $this->assertSame('credit', $side('443')); // TVA facturée — owed to the state
        $this->assertSame('credit', $side('701')); // Ventes
    }

    public function test_seeding_twice_does_not_duplicate_or_overwrite(): void
    {
        LedgerAccount::where('number', '411')->update(['name' => 'Clients divers']);

        $created = ChartOfAccounts::seed($this->company);

        $this->assertSame(0, $created);
        $this->assertSame(count(ChartOfAccounts::ROLES), LedgerAccount::count());
        // An accountant's edit survives a re-seed.
        $this->assertSame('Clients divers', LedgerAccount::where('number', '411')->value('name'));
    }

    public function test_an_unbalanced_entry_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unbalanced');

        $this->ledger()->post($this->company, 'OD', now()->toDateString(), [
            ['account' => 'cash', 'debit' => 1000],
            ['account' => 'sales_goods', 'credit' => 900],
        ]);
    }

    public function test_a_line_cannot_be_both_sides_or_negative(): void
    {
        foreach ([
            [['account' => 'cash', 'debit' => 100, 'credit' => 100]],
            [['account' => 'cash', 'debit' => -100]],
        ] as $lines) {
            try {
                $this->ledger()->post($this->company, 'OD', now()->toDateString(), $lines);
                $this->fail('An invalid line was accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_an_empty_or_zero_entry_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->ledger()->post($this->company, 'OD', now()->toDateString(), [
            ['account' => 'cash', 'debit' => 0],
        ]);
    }

    public function test_an_unknown_journal_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown journal');

        $this->ledger()->post($this->company, 'ZZ', now()->toDateString(), [
            ['account' => 'cash', 'debit' => 100],
            ['account' => 'sales_goods', 'credit' => 100],
        ]);
    }

    public function test_issuing_an_invoice_writes_a_balanced_sales_entry(): void
    {
        $document = $this->issueInvoice(100000);

        $entry = JournalEntry::with('lines.account')->where('source_id', $document->id)->firstOrFail();

        $this->assertSame('VE', $entry->journal);
        $this->assertTrue($entry->isBalanced());

        $byAccount = $entry->lines->mapWithKeys(fn ($l) => [
            $l->account->number => ['debit' => (float) $l->debit, 'credit' => (float) $l->credit],
        ]);

        // The customer owes the gross; income is the net; the tax was never
        // the business's money and is carried separately.
        $this->assertSame(119250.0, $byAccount['411']['debit']);
        $this->assertSame(100000.0, $byAccount['701']['credit']);
        $this->assertSame(19250.0, $byAccount['443']['credit']);
    }

    public function test_a_business_with_no_vat_posts_no_tax_line(): void
    {
        $this->company->forceFill(['vat_registered' => false])->save();
        app(CurrentCompany::class)->set($this->company->fresh());

        $document = $this->issueInvoice(100000);
        $entry = JournalEntry::with('lines.account')->where('source_id', $document->id)->firstOrFail();

        $this->assertTrue($entry->isBalanced());
        $this->assertCount(2, $entry->lines);
        $this->assertFalse($entry->lines->contains(fn ($l) => $l->account->number === '443'));
    }

    public function test_a_cash_payment_moves_the_debt_to_the_till(): void
    {
        $document = $this->issueInvoice(100000);

        $payment = app(PaymentRecorder::class)->record(
            $document, $this->user, (float) $document->total, PaymentMethod::Cash,
        );

        $entry = JournalEntry::with('lines.account')->where('source_id', $payment->id)->firstOrFail();

        $this->assertSame('CA', $entry->journal);
        $this->assertTrue($entry->isBalanced());

        $byAccount = $entry->lines->mapWithKeys(fn ($l) => [
            $l->account->number => ['debit' => (float) $l->debit, 'credit' => (float) $l->credit],
        ]);

        $this->assertSame(119250.0, $byAccount['571']['debit']);
        $this->assertSame(119250.0, $byAccount['411']['credit']);
    }

    public function test_a_bank_transfer_lands_in_the_bank_not_the_till(): void
    {
        $document = $this->issueInvoice(100000);

        $payment = app(PaymentRecorder::class)->record(
            $document, $this->user, (float) $document->total, PaymentMethod::BankTransfer,
        );

        $entry = JournalEntry::with('lines.account')->where('source_id', $payment->id)->firstOrFail();

        $this->assertSame('BQ', $entry->journal);
        $this->assertTrue($entry->lines->contains(fn ($l) => $l->account->number === '521'));
    }

    public function test_the_same_source_is_never_posted_twice(): void
    {
        $document = $this->issueInvoice(100000);

        $this->assertSame(1, JournalEntry::where('source_id', $document->id)->count());

        // A retry — a replayed sync envelope, a duplicated webhook — must not
        // double the books.
        $events = app(\App\Services\Accounting\RecordsBusinessEvents::class);
        $events->recordIssuedDocument($document->fresh(), $this->company, $this->user);
        $events->recordIssuedDocument($document->fresh(), $this->company, $this->user);

        $this->assertSame(1, JournalEntry::where('source_id', $document->id)->count());
    }

    public function test_after_a_sale_and_its_settlement_the_customer_owes_nothing(): void
    {
        $document = $this->issueInvoice(100000);

        app(PaymentRecorder::class)->record(
            $document, $this->user, (float) $document->total, PaymentMethod::Cash,
        );

        $receivables = LedgerAccount::where('number', '411')->firstOrFail();

        // Debited on the sale, credited on settlement — the account nets flat.
        $this->assertSame(0.0, $receivables->balance());
    }

    public function test_the_whole_ledger_balances(): void
    {
        $document = $this->issueInvoice(100000);
        app(PaymentRecorder::class)->record($document, $this->user, 50000, PaymentMethod::Cash);

        $this->assertSame(
            round((float) \App\Models\JournalLine::sum('debit'), 2),
            round((float) \App\Models\JournalLine::sum('credit'), 2),
        );
    }

    public function test_a_mistake_is_corrected_by_reversal_not_by_editing(): void
    {
        $entry = $this->ledger()->post($this->company, 'OD', now()->toDateString(), [
            ['account' => 'cash', 'debit' => 5000],
            ['account' => 'sales_goods', 'credit' => 5000],
        ]);

        $reversal = $this->ledger()->reverse($entry, $this->user);

        $this->assertSame($entry->id, $reversal->reverses_entry_id);
        $this->assertTrue($reversal->isBalanced());

        // The original stands — March still says what March said — and the two
        // together net to nothing.
        $this->assertNotNull($entry->fresh());
        $this->assertSame(0.0, LedgerAccount::where('number', '571')->firstOrFail()->balance());
    }

    public function test_a_company_with_no_chart_still_issues_invoices(): void
    {
        LedgerAccount::query()->delete();

        $document = $this->issueInvoice(100000);

        // Bookkeeping is never the reason a sale fails.
        $this->assertNotNull($document->number);
        $this->assertSame(0, JournalEntry::count());
    }

    protected function issueInvoice(float $amount): Document
    {
        $this->returned(Livewire::actingAs($this->user)
            ->test(Create::class, ['type' => 'invoice'])
            ->call('save', [
                'contact_id' => $this->contact->id,
                'issue_date' => now()->toDateString(),
                'due_date' => null,
                'notes' => '',
                'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => (string) $amount]],
            ], true));

        return Document::where('type', DocumentType::Invoice)->latest()->firstOrFail();
    }
}
