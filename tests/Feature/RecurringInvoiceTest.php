<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\RecurringInvoice;
use App\Models\Role;
use App\Models\User;
use App\Services\RecurringInvoices;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecurringInvoiceTest extends TestCase
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

    protected function schedule(array $attributes = []): RecurringInvoice
    {
        return RecurringInvoice::create(array_merge([
            'contact_id' => $this->customer->id,
            'name' => 'Monthly retainer',
            'lines' => [['description' => 'Retainer', 'quantity' => 1, 'unit_price' => 100000]],
            'discount_percent' => 0,
            'frequency' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-01-15',
            'next_run_on' => '2026-01-15',
            'payment_terms_days' => 14,
            'auto_issue' => false,
            'status' => RecurringInvoice::ACTIVE,
            'created_by' => $this->owner->id,
        ], $attributes));
    }

    protected function service(): RecurringInvoices
    {
        return app(RecurringInvoices::class);
    }

    public function test_a_due_schedule_raises_an_invoice(): void
    {
        $this->schedule();

        $result = $this->service()->run(Carbon::parse('2026-01-15'));

        $this->assertSame(1, $result['created']);

        $document = Document::latest('created_at')->firstOrFail();

        $this->assertSame($this->customer->id, $document->contact_id);
        $this->assertEqualsWithDelta(100000, (float) $document->subtotal, 0.01);
    }

    public function test_a_schedule_not_yet_due_raises_nothing(): void
    {
        $this->schedule(['next_run_on' => '2026-02-01']);

        $this->assertSame(0, $this->service()->run(Carbon::parse('2026-01-15'))['created']);
    }

    public function test_a_paused_schedule_raises_nothing(): void
    {
        $this->schedule(['status' => RecurringInvoice::PAUSED]);

        $this->assertSame(0, $this->service()->run(Carbon::parse('2026-06-01'))['created']);
    }

    /** The invoice belongs to the period it bills, not the day it was made. */
    public function test_the_invoice_is_dated_on_the_period_it_bills_for(): void
    {
        $this->schedule(['next_run_on' => '2026-01-15', 'max_occurrences' => 1]);

        // Run late, as a catch-up would.
        $this->service()->run(Carbon::parse('2026-01-28'));

        $document = Document::latest('created_at')->firstOrFail();

        $this->assertSame('2026-01-15', $document->issue_date->toDateString());
        $this->assertSame('2026-01-29', $document->due_date->toDateString(), '14-day terms from the issue date');
    }

    public function test_the_next_run_advances_by_the_frequency(): void
    {
        $schedule = $this->schedule(['max_occurrences' => 1]);

        $this->service()->run(Carbon::parse('2026-01-15'));

        $this->assertSame('2026-02-15', $schedule->fresh()->next_run_on->toDateString());
        $this->assertSame(1, $schedule->fresh()->occurrences);
    }

    /**
     * A monthly schedule starting on the 31st must not skip February. The plain
     * addMonths would jump 31 January to 3 March.
     */
    public function test_a_month_end_schedule_does_not_overflow(): void
    {
        $schedule = $this->schedule([
            'starts_on' => '2026-01-31',
            'next_run_on' => '2026-01-31',
            'max_occurrences' => 1,
        ]);

        $this->service()->run(Carbon::parse('2026-01-31'));

        $this->assertSame('2026-02-28', $schedule->fresh()->next_run_on->toDateString());
    }

    public function test_every_frequency_advances_correctly(): void
    {
        foreach ([
            'weekly' => '2026-01-22',
            'monthly' => '2026-02-15',
            'quarterly' => '2026-04-15',
            'yearly' => '2027-01-15',
        ] as $frequency => $expected) {
            $schedule = $this->schedule(['frequency' => $frequency, 'next_run_on' => '2026-01-15']);

            $this->service()->generate($schedule);

            $this->assertSame($expected, $schedule->fresh()->next_run_on->toDateString(), $frequency);
        }
    }

    /** A server down for three months must not silently skip three invoices. */
    public function test_a_missed_period_is_caught_up(): void
    {
        $schedule = $this->schedule(['next_run_on' => '2026-01-15']);

        $result = $this->service()->run(Carbon::parse('2026-04-20'));

        $this->assertSame(4, $result['created'], 'Jan, Feb, Mar and Apr should all be billed');
        $this->assertSame('2026-05-15', $schedule->fresh()->next_run_on->toDateString());
    }

    /** But a badly dated schedule must not generate hundreds in one night. */
    public function test_catch_up_is_capped_and_reported(): void
    {
        $this->schedule(['starts_on' => '2020-01-15', 'next_run_on' => '2020-01-15']);

        $result = $this->service()->run(Carbon::parse('2026-06-15'));

        $this->assertSame(RecurringInvoices::CATCH_UP_LIMIT, $result['created']);
        $this->assertContains('Monthly retainer', $result['capped'], 'the cap must be reported, not silent');
    }

    public function test_a_schedule_stops_at_its_occurrence_limit(): void
    {
        $schedule = $this->schedule(['max_occurrences' => 3]);

        $result = $this->service()->run(Carbon::parse('2026-12-31'));

        $this->assertSame(3, $result['created']);
        $this->assertSame(RecurringInvoice::FINISHED, $schedule->fresh()->status);
    }

    public function test_a_schedule_stops_at_its_end_date(): void
    {
        $schedule = $this->schedule(['ends_on' => '2026-03-31']);

        $result = $this->service()->run(Carbon::parse('2026-12-31'));

        $this->assertSame(3, $result['created'], 'January, February and March only');
        $this->assertSame(RecurringInvoice::FINISHED, $schedule->fresh()->status);
    }

    public function test_invoices_are_drafts_unless_auto_issue_is_on(): void
    {
        $this->schedule(['max_occurrences' => 1]);

        $this->service()->run(Carbon::parse('2026-01-15'));

        $this->assertSame(DocumentStatus::Draft, Document::latest('created_at')->firstOrFail()->status);
    }

    public function test_auto_issue_produces_a_numbered_invoice(): void
    {
        $this->schedule(['auto_issue' => true, 'max_occurrences' => 1]);

        $this->service()->run(Carbon::parse('2026-01-15'));

        $document = Document::latest('created_at')->firstOrFail();

        $this->assertNotSame(DocumentStatus::Draft, $document->status);
        $this->assertNotNull($document->number, 'an issued invoice needs its number');
    }

    public function test_a_discount_on_the_schedule_reaches_the_invoice(): void
    {
        $this->schedule(['discount_percent' => 15, 'max_occurrences' => 1]);

        $this->service()->run(Carbon::parse('2026-01-15'));

        $this->assertEqualsWithDelta(
            15000,
            (float) Document::latest('created_at')->firstOrFail()->discount_total,
            0.01,
        );
    }

    /** Schedules outlive the form that made them. */
    public function test_a_line_missing_its_unit_still_bills(): void
    {
        $this->schedule([
            'lines' => [['description' => 'Old line', 'unit_price' => 50000]],
            'max_occurrences' => 1,
        ]);

        $this->service()->run(Carbon::parse('2026-01-15'));

        $this->assertEqualsWithDelta(
            50000,
            (float) Document::latest('created_at')->firstOrFail()->subtotal,
            0.01,
        );
    }

    public function test_the_schedule_remembers_the_invoice_it_made(): void
    {
        $schedule = $this->schedule(['max_occurrences' => 1]);

        $this->service()->run(Carbon::parse('2026-01-15'));

        $this->assertNotNull($schedule->fresh()->last_document_id);
        $this->assertNotNull($schedule->fresh()->last_run_at);
    }

    /** The sweep runs with no tenant in scope, so it must find every company. */
    public function test_the_sweep_covers_every_company(): void
    {
        $this->schedule(['max_occurrences' => 1]);

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)), 'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id, 'currency' => 'XAF',
            'plan' => 'business', 'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);
        $otherCustomer = Contact::create(['name' => 'Autre Client', 'balance' => 0]);
        $this->schedule(['contact_id' => $otherCustomer->id, 'max_occurrences' => 1]);

        app(CurrentCompany::class)->set(null);

        $this->assertSame(2, $this->service()->run(Carbon::parse('2026-01-15'))['created']);
    }

    /** Each invoice must land in the right tenant. */
    public function test_a_generated_invoice_belongs_to_its_own_company(): void
    {
        $this->schedule(['max_occurrences' => 1]);

        app(CurrentCompany::class)->set(null);
        $this->service()->run(Carbon::parse('2026-01-15'));

        $document = Document::withoutGlobalScopes()->latest('created_at')->firstOrFail();

        $this->assertSame($this->company->id, $document->company_id);
    }

    public function test_the_rhythm_reads_in_words(): void
    {
        $this->assertSame('Monthly', $this->schedule()->rhythm());
        $this->assertSame('Every 2 weeks', $this->schedule(['frequency' => 'weekly', 'interval' => 2])->rhythm());
        $this->assertSame('Every 3 months', $this->schedule(['interval' => 3])->rhythm());
    }
}
