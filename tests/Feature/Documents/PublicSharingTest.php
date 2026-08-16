<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\DocumentSharing;

class PublicSharingTest extends DocumentsTestCase
{
    public function test_an_unknown_token_404s(): void
    {
        $this->get('/share/not-a-real-token')->assertNotFound();
    }

    public function test_an_open_share_shows_the_document_with_no_login(): void
    {
        $paper = $this->document(['title' => 'Supply agreement', 'body' => 'The agreed terms.']);
        $share = app(DocumentSharing::class)->create($paper, $this->owner);

        $this->get('/share/'.$share->share_token)
            ->assertOk()
            ->assertSee('Supply agreement');
    }

    public function test_viewing_records_an_access(): void
    {
        $paper = $this->document();
        $share = app(DocumentSharing::class)->create($paper, $this->owner);

        $this->get('/share/'.$share->share_token);

        $this->assertSame(1, $share->fresh()->accesses()->count());
    }

    public function test_a_revoked_share_is_refused(): void
    {
        $paper = $this->document();
        $share = app(DocumentSharing::class)->create($paper, $this->owner);
        app(DocumentSharing::class)->revoke($share);

        $this->get('/share/'.$share->share_token)
            ->assertOk()
            ->assertSee('no longer available');

        $this->assertSame(0, $share->fresh()->accesses()->count());
    }

    public function test_an_expired_share_is_refused(): void
    {
        $paper = $this->document();
        $share = app(DocumentSharing::class)->create($paper, $this->owner, now()->subDay());

        $this->get('/share/'.$share->share_token)
            ->assertOk()
            ->assertSee('expired');
    }

    public function test_a_password_protected_share_asks_for_the_password_first(): void
    {
        $paper = $this->document(['title' => 'Confidential terms']);
        $share = app(DocumentSharing::class)->create($paper, $this->owner, null, 'letmein');

        $this->get('/share/'.$share->share_token)
            ->assertOk()
            ->assertDontSee('Confidential terms')
            ->assertSee('password');
    }

    public function test_the_wrong_password_is_refused(): void
    {
        $paper = $this->document();
        $share = app(DocumentSharing::class)->create($paper, $this->owner, null, 'letmein');

        $this->post('/share/'.$share->share_token.'/unlock', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, $share->fresh()->accesses()->count());
    }

    public function test_the_right_password_unlocks_the_document(): void
    {
        $paper = $this->document(['title' => 'Confidential terms']);
        $share = app(DocumentSharing::class)->create($paper, $this->owner, null, 'letmein');

        $this->post('/share/'.$share->share_token.'/unlock', ['password' => 'letmein'])
            ->assertRedirect('/share/'.$share->share_token);

        $this->get('/share/'.$share->share_token)->assertOk()->assertSee('Confidential terms');
    }

    public function test_a_downloadable_share_shows_the_print_button(): void
    {
        $paper = $this->document();
        $share = app(DocumentSharing::class)->create($paper, $this->owner, null, null, true);

        $this->get('/share/'.$share->share_token)->assertSee('Print or save as PDF');
    }

    public function test_a_view_only_share_hides_the_print_button(): void
    {
        $paper = $this->document();
        $share = app(DocumentSharing::class)->create($paper, $this->owner, null, null, false);

        $this->get('/share/'.$share->share_token)->assertDontSee('Print or save as PDF');
    }
}
