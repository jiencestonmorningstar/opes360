<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DunningReminder;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvoiceOverdueNotification;
use App\Services\Dunning;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class DunningTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            'dunning' => ['enabled' => true],
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);

        $this->customer = Contact::create([
            'name' => 'Un Client',
            'email' => 'client@example.test',
            'balance' => 0,
        ]);
    }

    protected function invoice(string $dueDate, float $balance = 250000, ?Contact $contact = null): Document
    {
        return Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => ($contact ?? $this->customer)->id,
            'status' => DocumentStatus::Issued->value,
            'number' => 'INV-'.Str::upper(Str::random(6)),
            'issue_date' => Carbon::parse($dueDate)->subDays(30)->toDateString(),
            'due_date' => $dueDate,
            'currency' => 'XAF',
            'subtotal' => $balance, 'discount_total' => 0, 'tax_total' => 0,
            'total' => $balance, 'amount_paid' => 0, 'balance' => $balance,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function service(): Dunning
    {
        return app(Dunning::class);
    }

    public function test_an_invoice_past_the_first_step_is_chased(): void
    {
        $this->invoice('2026-06-01');

        $result = $this->service()->run(Carbon::parse('2026-06-10'));

        $this->assertSame(1, $result['sent']);
        Notification::assertSentTimes(InvoiceOverdueNotification::class, 1);
    }

    public function test_an_invoice_not_yet_at_a_step_is_left_alone(): void
    {
        $this->invoice('2026-06-01');

        $this->assertSame(0, $this->service()->run(Carbon::parse('2026-06-04'))['sent']);
        Notification::assertNothingSent();
    }

    public function test_an_invoice_not_yet_due_is_left_alone(): void
    {
        $this->invoice('2026-07-01');

        $this->assertSame(0, $this->service()->run(Carbon::parse('2026-06-10'))['sent']);
    }

    /**
     * The whole reason the reminders table exists. Without it, a nightly sweep
     * sends the same message every night until the invoice is paid.
     */
    public function test_a_step_is_never_sent_twice(): void
    {
        $this->invoice('2026-06-01');

        $this->service()->run(Carbon::parse('2026-06-10'));
        $second = $this->service()->run(Carbon::parse('2026-06-11'));

        $this->assertSame(0, $second['sent'], 'the same rung was sent again');
        Notification::assertSentTimes(InvoiceOverdueNotification::class, 1);
        $this->assertSame(1, DunningReminder::count());
    }

    public function test_a_later_step_is_still_sent(): void
    {
        $this->invoice('2026-06-01');

        $this->service()->run(Carbon::parse('2026-06-10'));   // 7-day rung
        $this->service()->run(Carbon::parse('2026-07-05'));   // 30-day rung

        Notification::assertSentTimes(InvoiceOverdueNotification::class, 2);
        $this->assertSame([7, 30], DunningReminder::orderBy('step')->pluck('step')->all());
    }

    /**
     * An invoice that slipped past a rung — the sweep did not run, or it was
     * created already overdue — must still be chased rather than falling
     * through the gaps forever.
     */
    public function test_an_invoice_that_skipped_a_rung_is_still_chased(): void
    {
        $this->invoice('2026-06-01');

        $result = $this->service()->run(Carbon::parse('2026-09-01'));

        $this->assertSame(1, $result['sent']);
        $this->assertSame(60, DunningReminder::first()->step, 'the highest reached rung should fire');
    }

    public function test_a_paid_invoice_is_not_chased(): void
    {
        $this->invoice('2026-06-01')->forceFill([
            'status' => DocumentStatus::Paid->value,
            'balance' => 0,
        ])->save();

        $this->assertSame(0, $this->service()->run(Carbon::parse('2026-06-10'))['sent']);
    }

    public function test_a_draft_is_not_chased(): void
    {
        $this->invoice('2026-06-01')->forceFill(['status' => DocumentStatus::Draft->value])->save();

        $this->assertSame(0, $this->service()->run(Carbon::parse('2026-06-10'))['sent']);
    }

    public function test_a_quotation_is_not_chased(): void
    {
        Document::create([
            'type' => DocumentType::Quotation,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Sent->value,
            'number' => 'QUO-1',
            'issue_date' => '2026-05-01', 'due_date' => '2026-06-01', 'currency' => 'XAF',
            'subtotal' => 900000, 'discount_total' => 0, 'tax_total' => 0,
            'total' => 900000, 'amount_paid' => 0, 'balance' => 900000,
            'created_by' => $this->owner->id,
        ]);

        $this->assertSame(0, $this->service()->run(Carbon::parse('2026-06-10'))['sent']);
    }

    /** Chasing small change costs more goodwill than it recovers. */
    public function test_a_balance_under_the_minimum_is_skipped(): void
    {
        $this->invoice('2026-06-01', 600);

        $result = $this->service()->run(Carbon::parse('2026-06-10'));

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_a_customer_with_no_email_is_skipped_not_crashed(): void
    {
        $silent = Contact::create(['name' => 'No Email', 'email' => null, 'balance' => 0]);
        $this->invoice('2026-06-01', 250000, $silent);

        $result = $this->service()->run(Carbon::parse('2026-06-10'));

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
    }

    /** Off unless asked for: these go to customers under the business's name. */
    public function test_a_company_without_dunning_enabled_sends_nothing(): void
    {
        $this->company->forceFill(['dunning' => ['enabled' => false]])->save();
        $this->invoice('2026-06-01');

        $this->assertSame(0, $this->service()->run(Carbon::parse('2026-06-10'))['companies']);
        Notification::assertNothingSent();
    }

    public function test_a_company_that_never_configured_dunning_sends_nothing(): void
    {
        $this->company->forceFill(['dunning' => null])->save();
        $this->invoice('2026-06-01');

        $this->assertSame(0, $this->service()->run(Carbon::parse('2026-06-10'))['companies']);
    }

    public function test_a_company_can_set_its_own_ladder(): void
    {
        $this->company->forceFill(['dunning' => ['enabled' => true, 'steps' => [3]]])->save();
        $this->invoice('2026-06-01');

        $this->assertSame(1, $this->service()->run(Carbon::parse('2026-06-05'))['sent']);
        $this->assertSame(3, DunningReminder::first()->step);
    }

    /** A ladder with no rungs would look switched on and chase nothing. */
    public function test_an_empty_ladder_falls_back_to_the_default(): void
    {
        $this->company->forceFill(['dunning' => ['enabled' => true, 'steps' => []]])->save();

        $this->assertSame(
            Dunning::DEFAULT_STEPS,
            $this->service()->settingsFor($this->company->fresh())['steps'],
        );
    }

    public function test_absurd_ladder_values_are_discarded(): void
    {
        $this->company->forceFill(['dunning' => ['enabled' => true, 'steps' => [-5, 0, 9999, 14]]])->save();

        $this->assertSame([14], $this->service()->settingsFor($this->company->fresh())['steps']);
    }

    public function test_the_reminder_records_what_was_owed_at_the_time(): void
    {
        $this->invoice('2026-06-01', 250000);

        $this->service()->run(Carbon::parse('2026-06-10'));

        $reminder = DunningReminder::first();

        $this->assertEqualsWithDelta(250000, (float) $reminder->balance, 0.01);
        $this->assertSame(9, $reminder->days_overdue);
        $this->assertSame('client@example.test', $reminder->sent_to);
    }

    public function test_one_companys_reminders_are_invisible_to_another(): void
    {
        $this->invoice('2026-06-01');
        $this->service()->run(Carbon::parse('2026-06-10'));

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)), 'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id, 'currency' => 'XAF',
            'plan' => 'business', 'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, DunningReminder::count());
    }

    public function test_the_command_is_quiet_when_nobody_opted_in(): void
    {
        $this->company->forceFill(['dunning' => null])->save();

        $this->artisan('opes:send-dunning-reminders')
            ->expectsOutputToContain('No business has reminders switched on.')
            ->assertExitCode(0);
    }

    public function test_the_command_reports_what_it_sent(): void
    {
        $this->invoice(now()->subDays(10)->toDateString());

        $this->artisan('opes:send-dunning-reminders')
            ->expectsOutputToContain('Sent 1 reminder across 1 business.')
            ->assertExitCode(0);
    }

    public function test_the_command_warns_about_what_it_skipped(): void
    {
        $silent = Contact::create(['name' => 'No Email', 'email' => null, 'balance' => 0]);
        $this->invoice(now()->subDays(10)->toDateString(), 250000, $silent);

        $this->artisan('opes:send-dunning-reminders')
            ->expectsOutputToContain('skipped')
            ->assertExitCode(0);
    }

    public function test_it_is_registered_on_the_daily_schedule(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('opes:send-dunning-reminders')
            ->assertExitCode(0);
    }
}
