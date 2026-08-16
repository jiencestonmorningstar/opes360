<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\DocumentSharing;

class DocumentSharingTest extends DocumentsTestCase
{
    public function test_a_share_link_can_be_created(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner);

        $this->assertNotEmpty($share->share_token);
        $this->assertTrue($share->allow_download);
        $this->assertTrue($share->isLive());
    }

    public function test_a_share_can_have_an_expiry(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner, now()->subDay());

        $this->assertTrue($share->isExpired());
        $this->assertFalse($share->isLive());
    }

    public function test_a_share_without_an_expiry_never_expires(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner);

        $this->assertFalse($share->isExpired());
    }

    public function test_a_password_protected_share_checks_the_password(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner, null, 'letmein');

        $this->assertTrue($share->isPasswordProtected());
        $this->assertTrue($share->checkPassword('letmein'));
        $this->assertFalse($share->checkPassword('wrong'));
    }

    /** The password is never stored in the clear. */
    public function test_the_password_is_hashed_not_stored_plainly(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner, null, 'letmein');

        $this->assertDatabaseMissing('business_document_shares', ['password_hash' => 'letmein']);
    }

    public function test_a_share_without_a_password_is_not_password_protected(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner);

        $this->assertFalse($share->isPasswordProtected());
    }

    public function test_download_can_be_disallowed(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner, null, null, false);

        $this->assertFalse($share->allow_download);
    }

    public function test_revoking_makes_a_share_no_longer_live(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner);

        $this->sharing()->revoke($share);

        $this->assertTrue($share->fresh()->isRevoked());
        $this->assertFalse($share->fresh()->isLive());
    }

    public function test_access_is_recorded(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner);

        $this->sharing()->recordAccess($share, '203.0.113.9', 'Mozilla/5.0');

        $this->assertSame(1, $share->accesses()->count());
        $this->assertSame('203.0.113.9', $share->accesses()->first()->ip_address);
    }

    public function test_a_document_can_have_more_than_one_share_link(): void
    {
        $paper = $this->document();
        $this->sharing()->create($paper, $this->owner);
        $this->sharing()->create($paper, $this->owner);

        $this->assertSame(2, $paper->fresh()->shares()->count());
    }

    /** The password is hidden from any array/JSON representation of the model. */
    public function test_the_password_hash_never_serialises(): void
    {
        $share = $this->sharing()->create($this->document(), $this->owner, null, 'letmein');

        $this->assertArrayNotHasKey('password_hash', $share->toArray());
    }

    protected function sharing(): DocumentSharing
    {
        return app(DocumentSharing::class);
    }
}
