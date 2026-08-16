<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentComment;
use App\Models\User;
use App\Notifications\DocumentCommentMention;
use RuntimeException;

/**
 * Commenting on a document, and the one side effect that goes with it:
 * telling whoever was mentioned.
 */
class DocumentComments
{
    /**
     * @param  array<int, int>  $mentionedUserIds  Explicit ids, not names
     *                                             parsed out of the text —
     *                                             see BusinessDocumentComment.
     */
    public function post(
        BusinessDocument $document,
        User $author,
        string $body,
        ?BusinessDocumentComment $parent = null,
        array $mentionedUserIds = [],
    ): BusinessDocumentComment {
        if ($parent !== null && $parent->business_document_id !== $document->id) {
            throw new RuntimeException('That comment belongs to a different document.');
        }

        $comment = BusinessDocumentComment::create([
            'business_document_id' => $document->id,
            'parent_id' => $parent?->id,
            'user_id' => $author->id,
            'body' => $body,
            'mentioned_user_ids' => array_values(array_unique($mentionedUserIds)),
        ]);

        $this->notifyMentioned($comment, $author);

        return $comment;
    }

    protected function notifyMentioned(BusinessDocumentComment $comment, User $author): void
    {
        if (empty($comment->mentioned_user_ids)) {
            return;
        }

        $recipients = User::query()
            ->whereIn('id', $comment->mentioned_user_ids)
            ->where('id', '!=', $author->id) // mentioning yourself needs no notice
            ->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new DocumentCommentMention($comment));
        }
    }

    public function resolve(BusinessDocumentComment $comment, User $actor): BusinessDocumentComment
    {
        $comment->update(['resolved_at' => now(), 'resolved_by' => $actor->id]);

        return $comment->fresh();
    }

    public function reopen(BusinessDocumentComment $comment): BusinessDocumentComment
    {
        $comment->update(['resolved_at' => null, 'resolved_by' => null]);

        return $comment->fresh();
    }
}
