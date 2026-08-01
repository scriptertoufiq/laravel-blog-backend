<?php

namespace App\Jobs\Blog;

use App\Models\Comment;
use App\Notifications\Blog\PostCommentedOn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emails the post's author that someone replied.
 *
 * Sending mail is a network round trip to a third party, so it happens here
 * rather than while the commenter waits for their comment to appear.
 */
class NotifyAuthorOfComment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public int $timeout = 30;

    public function __construct(public Comment $comment)
    {
        $this->onQueue('mail');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $comment = $this->comment->fresh(['post.author', 'author']);

        if ($comment === null) {
            return;
        }

        $postAuthor = $comment->post->author;

        // Nobody needs an email about their own comment.
        if ($postAuthor === null || $postAuthor->getKey() === $comment->user_id) {
            return;
        }

        $postAuthor->notify(new PostCommentedOn($comment));
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Failed to notify the author about a new comment.', [
            'comment_id' => $this->comment->getKey(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
