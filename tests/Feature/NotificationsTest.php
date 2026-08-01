<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Notifications\Bell;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Event;
use App\Models\Form;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Notifications\FormResponseReceivedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\TicketSoldNotification;
use App\Services\PaymentRecorder;
use App\Services\TicketSeller;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Notifications go out on mail and database channels only — no external
 * push service exists in this stack — and every dispatch must survive a
 * failure in the transport without unwinding the business action it rode in
 * on (a payment must post even if a mail server is unreachable).
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->owner->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($this->company, $this->owner);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    public function test_recording_a_payment_notifies_users_who_can_view_payments(): void
    {
        Notification::fake();

        $contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Issued,
            'number' => 'INV-2026-00001',
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 100,
            'total' => 100,
            'balance' => 100,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Work',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        app(PaymentRecorder::class)->record($document, $this->owner, 100.0, PaymentMethod::Cash);

        Notification::assertSentTo($this->owner, PaymentReceivedNotification::class);
    }

    public function test_a_ticket_sale_notifies_the_company(): void
    {
        Notification::fake();

        $event = Event::create([
            'title' => 'Launch Night',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $type = $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'price' => 10,
            'quantity' => 10,
        ]);

        app(TicketSeller::class)->sell($event, [$type->id => 1], 'Ada Obi', 'ada@example.com', null);

        Notification::assertSentTo($this->owner, TicketSoldNotification::class);
    }

    /**
     * A multi-ticket order used to resolve "who should hear about this"
     * once per ticket (looping NotifyCompany::about()) instead of once per
     * order — an N+1 that also meant a five-ticket order fired the
     * company-membership query five times. One notification per ticket is
     * still correct (door staff want a line per ticket), but the recipient
     * lookup itself must happen once.
     */
    public function test_a_multi_ticket_order_notifies_once_per_ticket_without_repeating_the_lookup(): void
    {
        Notification::fake();

        $event = Event::create([
            'title' => 'Launch Night',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $type = $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'price' => 10,
            'quantity' => 10,
        ]);

        app(TicketSeller::class)->sell($event, [$type->id => 3], 'Ada Obi', 'ada@example.com', null);

        // One notification per ticket sold, all to the same recipient.
        Notification::assertSentToTimes($this->owner, TicketSoldNotification::class, 3);
    }

    public function test_a_form_response_notifies_users_with_the_responses_permission(): void
    {
        Notification::fake();

        $staff = User::factory()->create();
        $this->joinCompany($this->company, $staff, Role::CASHIER); // no forms grants

        $form = Form::create([
            'title' => 'Registration',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => [
                ['id' => 'f-name', 'type' => 'short_text', 'label' => 'Name', 'required' => true, 'options' => []],
            ],
        ]);

        $this->post('/f/'.$form->share_token, ['answers' => ['f-name' => 'Ada']]);

        Notification::assertSentTo($this->owner, FormResponseReceivedNotification::class);
        Notification::assertNotSentTo($staff, FormResponseReceivedNotification::class);
    }

    public function test_the_bell_lists_and_marks_notifications_read(): void
    {
        $this->owner->notify(new PaymentReceivedNotification(
            Payment::forceCreate([
                'company_id' => $this->company->id,
                'method' => 'cash',
                'amount' => 50,
                'currency' => 'USD',
                'received_at' => now(),
            ])
        ));

        $notification = $this->owner->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertNull($notification->read_at);

        Livewire::actingAs($this->owner)
            ->test(Bell::class)
            ->assertSee('Payment received')
            ->call('markRead', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
    }
}
