<?php

namespace Tests\Feature\Service;

use App\Http\Controllers\TriagePublicController;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketEvent;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\Service\TicketDesk;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The walk-in triage page: a customer standing in the doorway scans the
 * business's printed QR and joins the service queue without a login.
 *
 * The routes are registered here exactly as the handoff asks the routes file
 * to carry them — this test suite is the contract those lines must satisfy.
 */
class TriageTest extends ServiceTestCase
{
    protected VerificationToken $token;

    protected function setUp(): void
    {
        parent::setUp();

        // The desk ships switched off; the triage door is part of the desk.
        $this->company->forceFill(['modules' => ['service' => true]])->save();

        $this->token = VerificationToken::create([
            'company_id' => $this->company->id,
            'subject_type' => Company::class,
            'subject_id' => $this->company->id,
            'token' => VerificationToken::newToken(),
        ]);

        // Mirrors the exact lines the handoff hands to routes/web.php. The
        // 'web' group is spelled out here only because routes/web.php gets it
        // implicitly and a test-registered route does not.
        Route::middleware(['web', 'throttle:60,1'])->group(function () {
            Route::get('/triage/{token}', [TriagePublicController::class, 'show'])->name('triage.show');
            Route::get('/triage/{token}/done', [TriagePublicController::class, 'done'])->name('triage.done');
        });
        Route::post('/triage/{token}', [TriagePublicController::class, 'submit'])
            ->middleware(['web', 'throttle:6,1'])->name('triage.submit');

        Route::getRoutes()->refreshNameLookups();
    }

    /** A valid walk-in submission, ready to be overridden per test. */
    protected function submission(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Marie Ngono',
            'phone' => '+237 650 11 22 33',
            'category' => 'repair',
            'urgency' => 'today',
            'description' => 'The generator will not start since this morning.',
        ], $overrides);
    }

    public function test_the_token_resolves_the_right_company_and_never_another_tenants(): void
    {
        $this->policy();

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            'modules' => ['service' => true],
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);

        $this->get('/triage/'.$this->token->token)
            ->assertOk()
            ->assertSee('Acme Sarl')
            ->assertDontSee('Other Sarl');

        $this->post('/triage/'.$this->token->token, $this->submission())->assertRedirect();

        $ticket = ServiceTicket::withoutGlobalScopes()->latest('opened_at')->first();
        $this->assertSame($this->company->id, $ticket->company_id);
        $this->assertNotSame($other->id, $ticket->company_id);
    }

    public function test_a_submission_opens_a_ticket_through_the_desk_with_the_sla_applied(): void
    {
        $this->policy();

        $this->post('/triage/'.$this->token->token, $this->submission())->assertRedirect();

        $ticket = ServiceTicket::query()->first();

        $this->assertNotNull($ticket);
        $this->assertSame('walk_in', $ticket->channel);
        $this->assertSame('new', $ticket->status);
        $this->assertStringStartsWith('TKT-', $ticket->reference);
        // The SLA clock ran: the desk made its promise on this ticket.
        $this->assertNotNull($ticket->response_due_at);
        $this->assertNotNull($ticket->resolution_due_at);
        // And the desk logged the opening like any other channel.
        $this->assertTrue(
            ServiceTicketEvent::query()->where('ticket_id', $ticket->id)->where('kind', 'opened')->exists()
        );
        $this->assertSame('Marie Ngono', $ticket->visitor_name);
    }

    public function test_a_known_phone_number_matches_the_existing_contact(): void
    {
        $this->policy();

        $existing = Contact::create([
            'name' => 'Marie Ngono',
            'balance' => 0,
            'phones' => ['+237 650 11 22 33'],
        ]);

        $this->post('/triage/'.$this->token->token, $this->submission(['phone' => '650112233']));

        $this->assertSame($existing->id, ServiceTicket::query()->first()->contact_id);
        // Matching, not minting: an unknown caller must never create a Contact.
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_an_unknown_visitor_does_not_create_a_contact(): void
    {
        $this->policy();

        $this->post('/triage/'.$this->token->token, $this->submission());

        $this->assertSame(0, Contact::query()->count());
        $ticket = ServiceTicket::query()->first();
        $this->assertNull($ticket->contact_id);
        $this->assertSame('Marie Ngono', $ticket->visitor_name);
        $this->assertSame('+237 650 11 22 33', $ticket->visitor_phone);
    }

    public function test_customer_very_urgent_caps_at_high_never_urgent(): void
    {
        $this->policy();

        $this->post('/triage/'.$this->token->token, $this->submission(['urgency' => 'now']));

        $this->assertSame('high', ServiceTicket::query()->first()->priority);
    }

    public function test_a_forged_urgency_value_is_rejected(): void
    {
        $this->policy();

        $this->post('/triage/'.$this->token->token, $this->submission(['urgency' => 'urgent']))
            ->assertSessionHasErrors('urgency');

        $this->assertSame(0, ServiceTicket::query()->count());
    }

    public function test_the_confirmation_shows_the_reference_large_and_the_queue_position(): void
    {
        $this->policy();

        // Someone walked in first and is still waiting.
        app(TicketDesk::class)->open([
            'subject' => 'Earlier walk-in',
            'channel' => 'walk_in',
        ], $this->owner, now()->subMinutes(10));

        $response = $this->post('/triage/'.$this->token->token, $this->submission());

        $ticket = ServiceTicket::query()->latest('opened_at')->first();

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee($ticket->reference)
            ->assertSee('1'); // one open walk-in ahead of them
    }

    public function test_a_bogus_token_404s(): void
    {
        $this->get('/triage/definitely-not-a-token')->assertNotFound();
        $this->post('/triage/definitely-not-a-token', $this->submission())->assertNotFound();
    }

    public function test_a_non_company_token_404s(): void
    {
        // A receipt or document token opens /v/, never the triage door.
        $stray = VerificationToken::create([
            'company_id' => $this->company->id,
            'subject_type' => Contact::class,
            'subject_id' => Str::ulid()->toBase32(),
            'token' => VerificationToken::newToken(),
        ]);

        $this->get('/triage/'.$stray->token)->assertNotFound();
    }

    public function test_a_disabled_service_module_shows_the_desk_as_closed(): void
    {
        $this->company->forceFill(['modules' => ['service' => false]])->save();

        $this->get('/triage/'.$this->token->token)
            ->assertOk()
            ->assertSee('not taking walk-in requests');

        $this->post('/triage/'.$this->token->token, $this->submission());

        $this->assertSame(0, ServiceTicket::withoutGlobalScopes()->count());
    }

    public function test_the_post_is_throttled(): void
    {
        $this->policy();

        foreach (range(1, 6) as $i) {
            $this->post('/triage/'.$this->token->token, $this->submission())->assertRedirect();
        }

        $this->post('/triage/'.$this->token->token, $this->submission())->assertStatus(429);
    }

    public function test_an_overlong_description_is_refused(): void
    {
        $this->policy();

        $this->post('/triage/'.$this->token->token, $this->submission([
            'description' => str_repeat('a', 2001),
        ]))->assertSessionHasErrors('description');
    }
}
