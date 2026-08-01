<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /**
     * Determine whether the user can update the post.
     *
     * Posts are only editable by the author who wrote them.
     */
    public function update(User $user, Post $post): bool
    {
        return $user->getKey() === $post->user_id;
    }

    /**
     * Determine whether the user can delete the post.
     */
    public function delete(User $user, Post $post): bool
    {
        return $this->update($user, $post);
    }
}
