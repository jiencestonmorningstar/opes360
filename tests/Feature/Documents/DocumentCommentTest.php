<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentComment;
use App\Models\Role;
use App\Notifications\DocumentCommentMention;
use App\Services\Documents\DocumentComments;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class DocumentCommentTest extends DocumentsTestCase
{
    public function test_a_comment_can_be_posted(): void
    {
        $paper = $this->document();

        $comment = $this->comments()->post($paper, $this->owner, 'Looks good to me.');

        $this->assertSame('Looks good to me.', $comment->body);
        $this->assertTrue($comment->author->is($this->owner));
        $this->assertFalse($comment->isReply());
    }

    public function test_a_reply_is_linked_to_its_parent(): void
    {
        $paper = $this->document();
        $original = $this->comments()->post($paper, $this->owner, 'Please clarify clause 3.');

        $reply = $this->comments()->post($paper, $this->owner, 'Done — see above.', $original);

        $this->assertTrue($reply->isReply());
        $this->assertTrue($reply->parent->is($original));
        $this->assertTrue($original->fresh()->replies->first()->is($reply));
    }

    public function test_a_reply_must_belong_to_the_same_document(): void
    {
        $paper = $this->document();
        $other = $this->document();
        $foreignComment = $this->comments()->post($other, $this->owner, 'On the other document.');

        $this->expectException(RuntimeException::class);

        $this->comments()->post($paper, $this->owner, 'Reply', $foreignComment);
    }

    public function test_mentioning_someone_notifies_them(): void
    {
        Notification::fake();
        $colleague = $this->memberAt(Role::MANAGER);
        $paper = $this->document();

        $this->comments()->post($paper, $this->owner, 'Over to you, @manager.', null, [$colleague->id]);

        Notification::assertSentTo($colleague, DocumentCommentMention::class);
    }

    public function test_mentioning_yourself_does_not_notify_you(): void
    {
        Notification::fake();
        $paper = $this->document();

        $this->comments()->post($paper, $this->owner, 'Note to self.', null, [$this->owner->id]);

        Notification::assertNothingSent();
    }

    public function test_a_comment_with_no_mentions_notifies_nobody(): void
    {
        Notification::fake();
        $paper = $this->document();

        $this->comments()->post($paper, $this->owner, 'Just a note.');

        Notification::assertNothingSent();
    }

    public function test_a_comment_can_be_resolved_and_reopened(): void
    {
        $paper = $this->document();
        $comment = $this->comments()->post($paper, $this->owner, 'Needs a decision.');

        $resolved = $this->comments()->resolve($comment, $this->owner);
        $this->assertTrue($resolved->isResolved());
        $this->assertSame($this->owner->id, $resolved->resolved_by);

        $reopened = $this->comments()->reopen($resolved);
        $this->assertFalse($reopened->isResolved());
        $this->assertNull($reopened->resolved_by);
    }

    public function test_deleting_a_document_takes_its_comments(): void
    {
        $paper = $this->document();
        $this->comments()->post($paper, $this->owner, 'A remark.');

        $paper->forceDelete();

        $this->assertDatabaseCount('business_document_comments', 0);
    }

    // ── Permissions ───────────────────────────────────────────────────────

    public function test_anybody_who_can_view_papers_may_comment(): void
    {
        $officer = $this->memberAt(Role::SALES_OFFICER);

        $this->assertTrue($officer->can('create', BusinessDocumentComment::class));
    }

    public function test_only_the_author_may_edit_their_own_comment(): void
    {
        $author = $this->memberAt(Role::MANAGER);
        $someoneElse = $this->memberAt(Role::SALES_OFFICER);
        $paper = $this->document();
        $comment = $this->comments()->post($paper, $author, 'Mine.');

        $this->assertTrue($author->can('update', $comment));
        $this->assertFalse($someoneElse->can('update', $comment));
    }

    public function test_a_document_administrator_may_delete_anyones_comment(): void
    {
        $author = $this->memberAt(Role::SALES_OFFICER);
        $admin = $this->memberAt(Role::ADMINISTRATOR);
        $paper = $this->document();
        $comment = $this->comments()->post($paper, $author, 'Mine.');

        $this->assertTrue($admin->can('delete', $comment));
    }

    public function test_the_documents_owner_may_resolve_a_comment_they_did_not_write(): void
    {
        $documentOwner = $this->memberAt(Role::MANAGER);
        $commenter = $this->memberAt(Role::SALES_OFFICER);
        $paper = $this->document(['owner_id' => $documentOwner->id]);
        $comment = $this->comments()->post($paper, $commenter, 'A remark.');

        $this->assertTrue($documentOwner->can('resolve', $comment));
    }

    public function test_a_bystander_may_not_resolve_a_comment(): void
    {
        $commenter = $this->memberAt(Role::SALES_OFFICER);
        $bystander = $this->memberAt(Role::SALES_OFFICER);
        $paper = $this->document();
        $comment = $this->comments()->post($paper, $commenter, 'A remark.');

        $this->assertFalse($bystander->can('resolve', $comment));
    }

    protected function comments(): DocumentComments
    {
        return app(DocumentComments::class);
    }
}
