<?php

namespace Tests\Feature\Documents;

use App\Models\Role;
use App\Services\Documents\DocumentComments;
use Laravel\Sanctum\Sanctum;

class CommentApiTest extends DocumentsTestCase
{
    public function test_a_comment_can_be_posted(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();

        $this->postJson("/api/v1/library/{$paper->id}/comments", ['body' => 'Looks fine.'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Looks fine.');
    }

    public function test_comments_are_listed_with_replies_nested(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();
        $original = app(DocumentComments::class)->post($paper, $this->owner, 'Question.');
        app(DocumentComments::class)->post($paper, $this->owner, 'Answer.', $original);

        $this->getJson("/api/v1/library/{$paper->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Question.')
            ->assertJsonPath('data.0.replies.0.body', 'Answer.');
    }

    public function test_a_comment_can_be_resolved_and_reopened(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $comment = app(DocumentComments::class)->post($this->document(), $this->owner, 'Needs review.');

        $this->postJson("/api/v1/library/comments/{$comment->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.resolved_at', fn ($v) => $v !== null);

        $this->postJson("/api/v1/library/comments/{$comment->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.resolved_at', null);
    }

    public function test_a_bystander_cannot_resolve_someone_elses_comment(): void
    {
        $author = $this->memberAt(Role::SALES_OFFICER);
        $bystander = $this->memberAt(Role::SALES_OFFICER);
        $comment = app(DocumentComments::class)->post($this->document(), $author, 'Mine.');

        Sanctum::actingAs($bystander, ['*']);

        $this->postJson("/api/v1/library/comments/{$comment->id}/resolve")->assertForbidden();
    }

    public function test_only_the_author_or_an_administrator_can_delete_a_comment(): void
    {
        $author = $this->memberAt(Role::SALES_OFFICER);
        $bystander = $this->memberAt(Role::SALES_OFFICER);
        $comment = app(DocumentComments::class)->post($this->document(), $author, 'Mine.');

        Sanctum::actingAs($bystander, ['*']);
        $this->deleteJson("/api/v1/library/comments/{$comment->id}")->assertForbidden();

        Sanctum::actingAs($author, ['*']);
        $this->deleteJson("/api/v1/library/comments/{$comment->id}")->assertNoContent();
    }

    public function test_commenting_on_a_restricted_document_you_cannot_see_is_refused(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        Sanctum::actingAs($clerk, ['*']);
        $paper = $this->document(['security' => 'restricted']);

        $this->postJson("/api/v1/library/{$paper->id}/comments", ['body' => 'Trying anyway.'])
            ->assertForbidden();
    }

    public function test_mentioning_a_colleague_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $colleague = $this->memberAt(Role::MANAGER);
        $paper = $this->document();

        $this->postJson("/api/v1/library/{$paper->id}/comments", [
            'body' => 'Over to you.',
            'mentioned_user_ids' => [$colleague->id],
        ])->assertCreated()->assertJsonPath('data.mentioned_user_ids.0', $colleague->id);
    }
}
