<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Reports\Collections as CollectionsScreen;
use App\Models\CollectionActivity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DunningReminder;
use App\Models\Role;
use App\Models\User;
use App\Support\CollectionsQueue;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The collections workspace: overdue accounts in the order somebody should
 * work through them, and a record of what was said when.
 *
 * The aging report can already say who is late. What it cannot say is who to
 * ring first, who was rung yesterday, and who promised to pay on Friday and
 * did not — so the same three customers get chased twice a week and the rest
 * are never chased at all.
 */
class CollectionsTest extends TestCase
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

    protected function queue(string $today = '2026-06-30'): CollectionsQueue
    {
        return new CollectionsQueue(Carbon::parse($today));
    }

    // ──────────────────────────────────────────────── who is in the queue ──

    public function test_an_overdue_customer_is_in_the_queue(): void
    {
        $client = $this->customer('Retardataire');
        $this->invoice($client, 200000, '2026-05-01');

        $rows = $this->queue()->accounts();

        $this->assertCount(1, $rows);
        $this->assertSame('Retardataire', $rows[0]['party']);
        $this->assertSame(200000.0, $rows[0]['overdue_total']);
        $this->assertSame(60, $rows[0]['oldest_days']);
    }

    public function test_a_customer_who_is_not_yet_due_is_not_chased(): void
    {
        $client = $this->customer('À jour');
        $this->invoice($client, 200000, '2026-07-20');

        $this->assertCount(0, $this->queue()->accounts());
    }

    public function test_a_customer_due_today_is_not_yet_late(): void
    {
        $client = $this->customer('Aujourd’hui');
        $this->invoice($client, 200000, '2026-06-30');

        $this->assertCount(0, $this->queue()->accounts());
    }

    public function test_an_invoice_not_yet_due_is_carried_alongside_the_overdue_part(): void
    {
        $client = $this->customer('Mixte');
        $this->invoice($client, 100000, '2026-05-01');
        $this->invoice($client, 400000, '2026-07-15');

        $row = $this->queue()->accounts()[0];

        $this->assertSame(100000.0, $row['overdue_total']);
        $this->assertSame(400000.0, $row['not_yet_due_total']);
        $this->assertSame(500000.0, $row['open_total']);
    }

    /** A debit note is owed money exactly as an invoice is. */
    public function test_an_overdue_debit_note_is_chased(): void
    {
        $client = $this->customer('Pénalisé');
        $this->invoice($client, 50000, '2026-05-01', DocumentType::DebitNote);

        $this->assertSame(50000.0, $this->queue()->accounts()[0]['overdue_total']);
    }

    /**
     * A credit note has an open balance too, and the outstanding scope does not
     * distinguish. Counting one as a receivable would have the business
     * chasing a customer for money it had itself written off.
     */
    public function test_an_unapplied_credit_note_is_not_a_debt(): void
    {
        $client = $this->customer('Crédité');
        $this->invoice($client, 100000, '2026-05-01');
        $this->invoice($client, 30000, '2026-05-01', DocumentType::CreditNote);

        $row = $this->queue()->accounts()[0];

        $this->assertSame(100000.0, $row['overdue_total']);
        $this->assertSame(30000.0, $row['credit_available']);
        $this->assertSame(70000.0, $row['net_exposure'], 'What is really worth chasing.');
    }

    public function test_a_paid_invoice_leaves_the_queue(): void
    {
        $client = $this->customer('Payé');
        $invoice = $this->invoice($client, 100000, '2026-05-01');
        $invoice->forceFill(['status' => DocumentStatus::Paid, 'amount_paid' => 100000, 'balance' => 0])->save();

        $this->assertCount(0, $this->queue()->accounts());
    }

    // ───────────────────────────────────────────────────────── ordering ──

    /**
     * Old money is worth chasing before big money. A million francs a week
     * late will very likely arrive; two hundred thousand at 150 days is the
     * one about to become a bad debt.
     */
    public function test_older_debt_outranks_merely_larger_debt(): void
    {
        $old = $this->customer('Vieux');
        $this->invoice($old, 200000, '2026-01-01');

        $big = $this->customer('Gros');
        $this->invoice($big, 900000, '2026-06-25');

        $rows = $this->queue()->accounts();

        $this->assertSame('Vieux', $rows[0]['party']);
        $this->assertGreaterThan($rows[1]['priority'], $rows[0]['priority']);
    }

    public function test_a_broken_promise_goes_to_the_top(): void
    {
        $quiet = $this->customer('Silencieux');
        $this->invoice($quiet, 800000, '2026-01-01');

        $broke = $this->customer('Promesse');
        $this->invoice($broke, 50000, '2026-06-01');
        $this->promise($broke, '2026-06-20', 50000);

        $rows = $this->queue()->accounts();

        $this->assertSame('Promesse', $rows[0]['party']);
        $this->assertSame('broken_promise', $rows[0]['flag']);
    }

    /**
     * Somebody who said they would pay on Friday should not be rung on
     * Thursday. They stay in the queue — the money is still owed — but below
     * everyone nobody has spoken to.
     */
    public function test_an_open_promise_moves_an_account_down_the_queue(): void
    {
        $promised = $this->customer('Engagé');
        $this->invoice($promised, 900000, '2026-01-01');
        $this->promise($promised, '2026-07-10', 900000);

        $ordinary = $this->customer('Ordinaire');
        $this->invoice($ordinary, 50000, '2026-06-20');

        $rows = $this->queue()->accounts();

        $this->assertSame('Ordinaire', $rows[0]['party']);
        $this->assertSame('promised', $rows[1]['flag']);
        $this->assertSame('2026-07-10', $rows[1]['promised_at']?->toDateString());
    }

    // ─────────────────────────────────────────────── what was said when ──

    public function test_the_queue_shows_the_last_reminder_sent(): void
    {
        $client = $this->customer('Relancé');
        $invoice = $this->invoice($client, 100000, '2026-05-01');

        DunningReminder::create([
            'company_id' => $this->company->id,
            'document_id' => $invoice->id,
            'contact_id' => $client->id,
            'step' => 30, 'days_overdue' => 30, 'balance' => 100000,
            'channel' => 'mail', 'sent_to' => 'a@b.cm', 'sent_at' => Carbon::parse('2026-06-01'),
        ]);

        $row = $this->queue()->accounts()[0];

        $this->assertSame('2026-06-01', $row['last_reminder_at']?->toDateString());
        $this->assertSame(30, $row['last_reminder_step']);
    }

    public function test_the_queue_shows_the_last_thing_somebody_did(): void
    {
        $client = $this->customer('Appelé');
        $this->invoice($client, 100000, '2026-05-01');

        CollectionActivity::create([
            'company_id' => $this->company->id,
            'contact_id' => $client->id,
            'user_id' => $this->owner->id,
            'kind' => CollectionActivity::KIND_CALL,
            'body' => 'Parlé au comptable',
            'happened_at' => Carbon::parse('2026-06-28 10:00'),
        ]);

        $row = $this->queue()->accounts()[0];

        $this->assertSame('Parlé au comptable', $row['last_activity']?->body);
        $this->assertSame(2, $row['days_since_contact']);
    }

    public function test_an_account_nobody_has_ever_touched_says_so(): void
    {
        $client = $this->customer('Jamais');
        $this->invoice($client, 100000, '2026-05-01');

        $row = $this->queue()->accounts()[0];

        $this->assertNull($row['last_activity']);
        $this->assertNull($row['days_since_contact']);
    }

    public function test_an_activity_belongs_to_the_company_that_logged_it(): void
    {
        $client = $this->customer('Cloisonné');
        $this->invoice($client, 100000, '2026-05-01');

        $activity = CollectionActivity::create([
            'company_id' => $this->company->id,
            'contact_id' => $client->id,
            'kind' => CollectionActivity::KIND_NOTE,
            'body' => 'Interne',
            'happened_at' => Carbon::parse('2026-06-28'),
        ]);

        $this->assertSame($this->company->id, $activity->company_id);
        $this->assertSame(1, CollectionActivity::query()->count());
    }

    // ─────────────────────────────────────────────────────── the screen ──

    public function test_the_screen_lists_overdue_accounts(): void
    {
        $client = $this->customer('Retardataire');
        $this->invoice($client, 200000, now()->subDays(45)->toDateString());

        Livewire::actingAs($this->owner)
            ->test(CollectionsScreen::class)
            ->assertSee('Retardataire')
            ->assertSee('Collections');
    }

    public function test_the_screen_calls_out_a_broken_promise(): void
    {
        $client = $this->customer('Promesse');
        $this->invoice($client, 200000, now()->subDays(45)->toDateString());
        $this->promise($client, now()->subDays(3)->toDateString(), 200000);

        Livewire::actingAs($this->owner)
            ->test(CollectionsScreen::class)
            ->call('toggleParty', $client->id)
            ->assertSee('promised')
            ->set('filter', 'broken')
            ->assertSee('Promesse');
    }

    public function test_the_screen_logs_a_call(): void
    {
        $client = $this->customer('Retardataire');
        $this->invoice($client, 200000, now()->subDays(45)->toDateString());

        Livewire::actingAs($this->owner)
            ->test(CollectionsScreen::class)
            ->call('openLog', $client->id)
            ->set('kind', CollectionActivity::KIND_CALL)
            ->set('body', 'Rappelle lundi')
            ->call('saveLog')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('collection_activities', [
            'contact_id' => $client->id,
            'kind' => CollectionActivity::KIND_CALL,
            'body' => 'Rappelle lundi',
        ]);
    }

    public function test_the_screen_records_a_promise_to_pay(): void
    {
        $client = $this->customer('Engagé');
        $this->invoice($client, 200000, now()->subDays(45)->toDateString());

        Livewire::actingAs($this->owner)
            ->test(CollectionsScreen::class)
            ->call('openLog', $client->id)
            ->set('kind', CollectionActivity::KIND_PROMISE)
            ->set('body', 'Paiera vendredi')
            ->set('promisedAt', now()->addDays(5)->toDateString())
            ->set('promisedAmount', '200000')
            ->call('saveLog')
            ->assertHasNoErrors();

        $activity = CollectionActivity::query()->firstOrFail();

        $this->assertSame(200000.0, (float) $activity->promised_amount);
        $this->assertSame(now()->addDays(5)->toDateString(), $activity->promised_at?->toDateString());
    }

    public function test_a_promise_needs_a_date(): void
    {
        $client = $this->customer('Engagé');
        $this->invoice($client, 200000, now()->subDays(45)->toDateString());

        Livewire::actingAs($this->owner)
            ->test(CollectionsScreen::class)
            ->call('openLog', $client->id)
            ->set('kind', CollectionActivity::KIND_PROMISE)
            ->set('body', 'Paiera un jour')
            ->call('saveLog')
            ->assertHasErrors('promisedAt');
    }

    public function test_a_logged_note_needs_something_written_in_it(): void
    {
        $client = $this->customer('Vide');
        $this->invoice($client, 200000, now()->subDays(45)->toDateString());

        Livewire::actingAs($this->owner)
            ->test(CollectionsScreen::class)
            ->call('openLog', $client->id)
            ->set('kind', CollectionActivity::KIND_NOTE)
            ->set('body', '')
            ->call('saveLog')
            ->assertHasErrors('body');
    }

    public function test_the_screen_exports_the_queue(): void
    {
        $client = $this->customer('Retardataire');
        $this->invoice($client, 200000, now()->subDays(45)->toDateString());

        Livewire::actingAs($this->owner)
            ->test(CollectionsScreen::class)
            ->call('export')
            ->assertOk();
    }

    // ───────────────────────────────────────────────────────── helpers ──

    protected function customer(string $name): Contact
    {
        return Contact::create(['name' => $name, 'type' => 'customer', 'balance' => 0]);
    }

    protected function invoice(
        Contact $contact,
        float $total,
        string $dueDate,
        DocumentType $type = DocumentType::Invoice,
    ): Document {
        return Document::create([
            'type' => $type,
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Issued,
            'number' => $type->prefix().'-'.Str::upper(Str::random(6)),
            'issue_date' => Carbon::parse($dueDate)->subDays(30)->toDateString(),
            'due_date' => $dueDate,
            'currency' => 'XAF',
            'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0,
            'total' => $total, 'amount_paid' => 0, 'balance' => $total,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function promise(Contact $contact, string $on, float $amount): CollectionActivity
    {
        return CollectionActivity::create([
            'company_id' => $this->company->id,
            'contact_id' => $contact->id,
            'user_id' => $this->owner->id,
            'kind' => CollectionActivity::KIND_PROMISE,
            'body' => 'Promesse',
            'promised_at' => $on,
            'promised_amount' => $amount,
            'happened_at' => Carbon::parse($on)->subDays(3),
        ]);
    }
}
