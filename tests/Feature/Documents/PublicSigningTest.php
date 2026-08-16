<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\DocumentSignatureRequests;

class PublicSigningTest extends DocumentsTestCase
{
    public function test_an_unknown_token_shows_an_unknown_page(): void
    {
        $this->get('/sign/not-a-real-token')->assertNotFound();
    }

    public function test_the_signing_page_opens_with_no_login(): void
    {
        $paper = $this->document();
        $signature = app(DocumentSignatureRequests::class)
            ->request($paper, [['name' => 'A Client', 'email' => 'client@example.com']])
            ->first();

        $this->get('/sign/'.$signature->signing_token)
            ->assertOk()
            ->assertSee('A Client');
    }

    public function test_typing_a_name_and_submitting_signs_it(): void
    {
        $paper = $this->document();
        $signature = app(DocumentSignatureRequests::class)
            ->request($paper, [['name' => 'A Client', 'email' => 'client@example.com']])
            ->first();

        $this->post('/sign/'.$signature->signing_token, ['typed_name' => 'A Client'])
            ->assertRedirect('/sign/'.$signature->signing_token);

        $this->assertTrue($signature->fresh()->isSigned());
    }

    public function test_declining_with_a_reason(): void
    {
        $paper = $this->document();
        $signature = app(DocumentSignatureRequests::class)
            ->request($paper, [['name' => 'A Client', 'email' => 'client@example.com']])
            ->first();

        $this->post('/sign/'.$signature->signing_token.'/decline', ['reason' => 'Need changes.'])
            ->assertRedirect('/sign/'.$signature->signing_token);

        $this->assertSame('declined', $signature->fresh()->status);
        $this->assertSame('Need changes.', $signature->fresh()->declined_reason);
    }

    public function test_a_signed_link_cannot_be_signed_again(): void
    {
        $paper = $this->document();
        $signature = app(DocumentSignatureRequests::class)
            ->request($paper, [['name' => 'A Client', 'email' => 'client@example.com']])
            ->first();
        app(DocumentSignatureRequests::class)->sign($signature);

        $this->post('/sign/'.$signature->signing_token, ['typed_name' => 'A Client'])
            ->assertSessionHasErrors('signature');

        $this->assertSame('signed', $signature->fresh()->status);
    }

    /**
     * Two different businesses' documents must never leak into each other's
     * signing page — the token names the company, resolved as it, same as
     * VerificationController.
     */
    public function test_a_signature_from_another_company_is_resolved_as_that_company(): void
    {
        $paperCompanyOwner = $this->owner;
        $paper = $this->document();
        $signature = app(DocumentSignatureRequests::class)
            ->request($paper, [['name' => 'A Client', 'email' => 'client@example.com']])
            ->first();

        // No session at all for this request — the signer is not one of our users.
        $this->flushSession();

        $this->get('/sign/'.$signature->signing_token)
            ->assertOk()
            ->assertSee($this->company->name);
    }
}
