<?php

namespace App\Services\Blog;

use App\Jobs\Blog\NotifyAuthorOfComment;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CommentService
{
    public function __construct(private readonly PostService $posts) {}

    /**
     * Read a page of a post's comments, newest first.
     *
     * Comments are read straight from the database rather than cache: they turn
     * over far faster than posts do, and a reader who has just left one expects
     * to see it immediately.
     *
     * @return LengthAwarePaginator<int, Comment>
     */
    public function paginate(Post $post, int $page, int $perPage): LengthAwarePaginator
    {
        return $post->comments()
            ->with('author')
            ->latest('created_at')
            ->latest('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Add a comment to a post and let the post's author know.
     */
    public function create(Post $post, User $author, string $body): Comment
    {
        // `user_id` is deliberately not mass assignable, so the author is
        // attached through the relationship rather than the attribute array.
        $comment = $post->comments()->make(['body' => $body]);
        $comment->author()->associate($author);
        $comment->save();

        // The cached post payloads carry a comment count that is now stale.
        $this->posts->forget($post);

        NotifyAuthorOfComment::dispatch($comment)->afterCommit();

        return $comment->load('author');
    }

    /**
     * Replace the text of an existing comment.
     */
    public function update(Comment $comment, string $body): Comment
    {
        $comment->fill(['body' => $body]);
        $comment->edited_at = now();
        $comment->save();

        return $comment->load('author');
    }

    /**
     * Remove a comment.
     */
    public function delete(Comment $comment): void
    {
        $post = $comment->post;

        $comment->delete();

        $this->posts->forget($post);
    }
}
