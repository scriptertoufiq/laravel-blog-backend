<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * Determine whether the user can edit the comment.
     *
     * Only the person who wrote it — the post's author does not get to put
     * words in someone else's mouth.
     */
    public function update(User $user, Comment $comment): bool
    {
        return $user->getKey() === $comment->user_id;
    }

    /**
     * Determine whether the user can delete the comment.
     *
     * The comment's author can remove it, and so can the author of the post it
     * sits on, so a writer can moderate their own thread.
     */
    public function delete(User $user, Comment $comment): bool
    {
        return $user->getKey() === $comment->user_id
            || $user->getKey() === $comment->post->user_id;
    }
}
