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
    /**
     * @param  string|null  $anchorId  A block id the editor stamped on a
     *                                 paragraph/heading/list-item/table-row —
     *                                 §8.3's "anchors comment threads
     *                                 directly to explicit block elements".
     *                                 Null keeps a comment document-level,
     *                                 exactly as before this existed.
     */
    public function post(
        BusinessDocument $document,
        User $author,
        string $body,
        ?BusinessDocumentComment $parent = null,
        array $mentionedUserIds = [],
        ?string $anchorId = null,
    ): BusinessDocumentComment {
        if ($parent !== null && $parent->business_document_id !== $document->id) {
            throw new RuntimeException('That comment belongs to a different document.');
        }

        // A reply inherits its parent's anchor rather than needing its own —
        // a thread stays pinned to the block it started on.
        $anchorId ??= $parent?->anchor_id;

        $comment = BusinessDocumentComment::create([
            'business_document_id' => $document->id,
            'anchor_id' => $anchorId,
            'parent_id' => $parent?->id,
            'user_id' => $author->id,
            'body' => $body,
            'mentioned_user_ids' => array_values(array_unique($mentionedUserIds)),
        ]);

        $this->notifyMentioned($comment, $author);

        // Separate from the mention notification: this fires for every
        // comment, mentioned or not, for a rule that just wants to know a
        // document is being discussed.
        $document->emitDomainEvent('document.commented', ['comment_id' => $comment->id]);

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

    /**
     * How many unresolved threads are pinned to each block — what lets the
     * editor draw a marker only on blocks somebody is actually discussing,
     * without pulling every comment's body down just to count them.
     *
     * @return array<string, int> anchor_id => open thread count
     */
    public function openThreadCountsByAnchor(BusinessDocument $document): array
    {
        return BusinessDocumentComment::query()
            ->where('business_document_id', $document->id)
            ->whereNull('parent_id')
            ->whereNull('resolved_at')
            ->whereNotNull('anchor_id')
            ->selectRaw('anchor_id, count(*) as total')
            ->groupBy('anchor_id')
            ->pluck('total', 'anchor_id')
            ->all();
    }
}
