<?php

namespace App\Policies;

use App\Models\BusinessDocumentComment;
use App\Models\User;

/**
 * Commenting needs only the ability to see the document being discussed —
 * not a separate permission of its own, since a comment is conversation
 * about something you can already read, not a new capability.
 *
 * Deleting and resolving are narrower: the comment's own author, the
 * document's owner (they are accountable for what happens on it), or a
 * document administrator.
 */
class BusinessDocumentCommentPolicy
{
    public function create(User $user): bool
    {
        return $user->can('papers.view');
    }

    public function update(User $user, BusinessDocumentComment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    public function delete(User $user, BusinessDocumentComment $comment): bool
    {
        return $comment->user_id === $user->id || $user->can('papers.manage');
    }

    public function resolve(User $user, BusinessDocumentComment $comment): bool
    {
        return $comment->user_id === $user->id
            || $comment->document?->owner_id === $user->id
            || $user->can('papers.manage');
    }
}
