<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /embed variants are the only routes allowed inside a third-party
 * iframe, and they must work without our session cookie — inside someone
 * else's site the browser strips it, which is also why the embedded submit
 * is exempt from CSRF and re-renders errors in place instead of redirecting.
 */
class EmbedTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ]);

        app(CurrentCompany::class)->set($this->company);
    }

    protected function openForm(): Form
    {
        return Form::create([
            'title' => 'Registration',
            'status' => 'open',
            'share_token' => Form::newShareToken(),
            'fields' => [
                ['id' => 'f-name', 'type' => 'short_text', 'label' => 'Your name', 'required' => true, 'options' => []],
            ],
        ]);
    }

    public function test_the_form_embed_allows_framing_while_everything_else_does_not(): void
    {
        $form = $this->openForm();

        $embed = $this->get('/f/'.$form->share_token.'/embed');
        $embed->assertOk();
        $embed->assertHeaderMissing('X-Frame-Options');
        $this->assertStringContainsString('frame-ancestors *', $embed->headers->get('Content-Security-Policy'));

        // The ordinary share page keeps the lock.
        $public = $this->get('/f/'.$form->share_token);
        $public->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString("frame-ancestors 'none'", $public->headers->get('Content-Security-Policy'));
    }

    public function test_the_embedded_submit_works_without_a_csrf_token(): void
    {
        $form = $this->openForm();

        // No session, no token — exactly what a cross-site iframe can manage.
        $this->post('/f/'.$form->share_token.'/embed', [
            'answers' => ['f-name' => 'Ada'],
        ])->assertOk()->assertSee('Response recorded');

        $this->assertSame('Ada', FormResponse::sole()->answers['f-name']);
    }

    public function test_embedded_validation_errors_render_in_place_with_input_kept(): void
    {
        $form = $this->openForm();

        $response = $this->post('/f/'.$form->share_token.'/embed', ['answers' => []]);

        // In-place re-render, not a redirect a cookie-less iframe would lose.
        $response->assertStatus(422)->assertSee('Your name');

        $this->assertSame(0, FormResponse::count());
    }

    public function test_the_event_embed_shows_the_card_and_links_out(): void
    {
        $event = Event::create([
            'title' => 'Launch Night',
            'starts_at' => now()->addWeek(),
            'status' => 'published',
            'share_token' => Event::newShareToken(),
        ]);

        $event->ticketTypes()->create([
            'company_id' => $this->company->id,
            'name' => 'General',
            'price' => 25,
            'quantity' => 50,
        ]);

        $response = $this->get('/e/'.$event->share_token.'/embed');

        $response->assertOk()
            ->assertHeaderMissing('X-Frame-Options')
            ->assertSee('Launch Night')
            ->assertSee('General')
            ->assertSee($event->publicUrl());

        $this->assertStringContainsString('frame-ancestors *', $response->headers->get('Content-Security-Policy'));
    }
}
